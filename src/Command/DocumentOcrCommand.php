<?php

declare(strict_types=1);

namespace App\Command;

use App\Document\DocumentLibrary;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** @author Tac Tacelosky <tacman@gmail.com> */
#[AsCommand('app:document:ocr', 'Save resumable Mistral OCR for a registered document’s upright reading pages')]
final class DocumentOcrCommand
{
    public function __construct(
        private readonly DocumentLibrary $library,
        private readonly HttpClientInterface $http,
        private readonly LockFactory $lockFactory,
        #[Autowire('%env(MISTRAL_API_KEY)%')] private readonly string $apiKey,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Document code in pdfs.json')] string $code,
        #[Option('Comma-separated reading page numbers (1-based); default: all')] string $pages = '',
        #[Option('Concurrent requests')] int $chunkSize = 4,
        #[Option('OCR model')] string $model = 'mistral-ocr-latest',
    ): int {
        if ('' === $this->apiKey || $chunkSize < 1 || $chunkSize > 32) {
            $io->error('Set MISTRAL_API_KEY and use a chunk size between 1 and 32.');
            return Command::INVALID;
        }
        $entry = $this->library->get($code);
        $dir = $this->library->directory($code);
        $leaves = json_decode(file_get_contents($dir.'/leaves.json'), true, flags: JSON_THROW_ON_ERROR)['pages'];
        $count = count($leaves);
        $requested = '' === $pages ? range(1, $count) : explode(',', $pages);
        foreach ($requested as $scan) {
            if (!ctype_digit((string) $scan) || (int) $scan < 1 || (int) $scan > $count) {
                $io->error(sprintf('Reading page numbers must be between 1 and %d.', $count));
                return Command::INVALID;
            }
        }
        $requested = array_unique(array_map('intval', $requested));
        $lock = $this->lockFactory->createLock('document-ocr-'.$code, 3600);
        if (!$lock->acquire()) {
            $io->error('OCR is already running for this document.');
            return Command::FAILURE;
        }
        try {
            $fs = new Filesystem();
            $fs->mkdir($dir.'/ocr');
            $fingerprint = hash_file('sha256', $dir.'/source.pdf');
            $settings = ['source_sha256' => $fingerprint, 'leaves_sha256' => hash_file('sha256', $dir.'/leaves.json'), 'requested_model' => $model];
            $statePath = $dir.'/ocr/settings.json';
            if (is_file($statePath) && json_decode(file_get_contents($statePath), true) !== $settings) {
                throw new \RuntimeException('The source or model changed. Archive the existing OCR directory before reprocessing.');
            }
            $this->write($statePath, $settings);
            $missing = array_filter($requested, static fn (int $scan): bool => !is_file(sprintf('%s/ocr/%03d.json', $dir, $scan)));
            if ([] !== $missing) {
                foreach (array_chunk($missing, $chunkSize) as $chunk) {
                    $responses = [];
                    foreach ($chunk as $number) {
                        $leaf = $leaves[$number - 1];
                        $io->writeln(sprintf('OCR reading page %d (scan %d, %s)', $number, $leaf['scan'], $leaf['side']));
                        $responses[$number] = $this->http->request('POST', 'https://api.mistral.ai/v1/ocr', [
                            'auth_bearer' => $this->apiKey,
                            'json' => [
                                'model' => $model,
                                'document' => ['type' => 'image_url', 'image_url' => 'data:image/png;base64,'.base64_encode(file_get_contents($dir.'/'.$leaf['image']))],
                                'include_image_base64' => false,
                                'confidence_scores_granularity' => 'page',
                            ],
                            'timeout' => 300, 'max_duration' => 600,
                        ]);
                    }
                    foreach ($responses as $number => $response) {
                        $raw = $response->toArray();
                        $this->write(sprintf('%s/ocr/response-%03d.json', $dir, $number), $raw);
                        if (count($raw['pages'] ?? []) !== 1 || !is_string($raw['pages'][0]['markdown'] ?? null)) {
                            throw new \RuntimeException('Unexpected OCR result. Raw response saved for inspection.');
                        }
                        $this->write(sprintf('%s/ocr/%03d.json', $dir, $number), [
                            'leaf' => $leaves[$number - 1], 'model' => $raw['model'], 'page' => $raw['pages'][0],
                        ]);
                        $this->assemble($code, $entry, $fingerprint);
                    }
                    $lock->refresh();
                }
            }
            $total = $this->assemble($code, $entry, $fingerprint);
            $io->success(sprintf('%d/%d reading pages saved. Existing pages are reused on the next run.', $total, $count));
            return Command::SUCCESS;
        } finally {
            $lock->release();
        }
    }

    private function assemble(string $code, array $entry, string $fingerprint): int
    {
        $dir = $this->library->directory($code);
        $pages = [];
        foreach (glob($dir.'/ocr/[0-9][0-9][0-9].json') as $file) {
            $saved = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
            $pages[] = [
                ...$saved['leaf'], 'markdown' => $saved['page']['markdown'],
                'model' => $saved['model'], 'confidence_scores' => $saved['page']['confidence_scores'] ?? null,
            ];
        }
        usort($pages, static fn (array $a, array $b): int => $a['number'] <=> $b['number']);
        $this->write($dir.'/transcript.json', [
            'title' => $entry['title'], 'source_sha256' => $fingerprint,
            'language' => 'de', 'review_status' => 'machine transcription, not fully proofread',
            'complete' => count($pages) === count(json_decode(file_get_contents($dir.'/leaves.json'), true)['pages']),
            'scan_count' => $entry['metadata']['scan_count'], 'pages' => $pages,
        ]);
        (new Filesystem())->dumpFile($dir.'/transcript.de.md', implode("\n\n", array_map(
            static fn (array $page): string => sprintf("## Reading page %d — Scan %d, %s\n\n%s", $page['number'], $page['scan'], $page['side'], $page['markdown']), $pages,
        ))."\n");
        return count($pages);
    }

    private function write(string $path, array $data): void
    {
        (new Filesystem())->dumpFile($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
    }
}
