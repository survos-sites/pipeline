<?php

declare(strict_types=1);

namespace App\Command;

use App\Benchmark\TranscriptionMetrics;
use Symfony\AI\Platform\Message\Content\Image;
use Symfony\AI\Platform\Message\Content\ImageUrl;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Contracts\Service\ServiceProviderInterface;

#[AsCommand('app:benchmark:run', 'Compare a configured Symfony AI OCR/vision model against frozen human references')]
final class BenchmarkRunCommand
{
    public const PROMPT = 'Transcribe all visible text in reading order, including handwritten additions and corrections. Preserve original spelling, case and punctuation. Use plain text, no Markdown formatting, no explanation. Mark unreadable text [illegible]. Do not infer missing text.';

    public function __construct(
        #[AutowireLocator('ai.platform', indexAttribute: 'name')] private readonly ServiceProviderInterface $platforms,
        private readonly TranscriptionMetrics $metrics,
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Path to frozen manifest.json')] string $manifest,
        #[Argument('Configured platform name')] string $platform,
        #[Argument('Model identifier')] string $model,
        #[Argument('New output directory')] string $output,
        #[Option('Use Mistral OCR content input instead of vision messages')] bool $ocr = false,
        #[Option('Comma-separated page IDs; default all')] string $pages = '',
        #[Option('Known catalog model whose class/capabilities apply to an explicit version ID')] ?string $modelTemplate = null,
    ): int {
        if (!$this->platforms->has($platform) || file_exists($output)) {
            throw new \InvalidArgumentException('Choose a configured platform and a new output directory.');
        }
        $data = json_decode(file_get_contents($manifest), true, flags: JSON_THROW_ON_ERROR);
        $aiPlatform = $this->platforms->get($platform);
        $requestedModel = $model;
        if (null !== $modelTemplate) {
            $template = $aiPlatform->getModelCatalog()->getModel($modelTemplate);
            $requestedModel = new ($template::class)($model, $template->getCapabilities(), $template->getOptions());
        }
        $selected = '' === $pages ? array_column($data['pages'], 'id') : explode(',', $pages);
        if (array_diff($selected, array_column($data['pages'], 'id'))) {
            throw new \InvalidArgumentException('Unknown page IDs.');
        }
        $fs = new Filesystem();
        $fs->mkdir($output);
        $report = [
            'started_at' => gmdate(DATE_ATOM), 'platform' => $platform, 'requested_model' => $model,
            'model_template' => $modelTemplate,
            'manifest_sha256' => hash_file('sha256', $manifest), 'input_mode' => $ocr ? 'ocr' : 'vision',
            'prompt' => $ocr ? null : self::PROMPT,
            'normalization' => 'NFC and collapsed whitespace only; case/punctuation/Markdown retained',
            'cost_usd' => null, 'cost_note' => 'Not estimated; raw provider usage retained for pricing separately.',
            'pages' => [],
        ];
        $failed = false;
        foreach ($data['pages'] as $page) {
            if (!in_array($page['id'], $selected, true)) {
                continue;
            }
            $imagePath = dirname($manifest).'/'.$page['image'];
            $referencePath = dirname($manifest).'/'.$page['reference'];
            if (hash_file('sha256', $imagePath) !== $page['image_sha256'] || hash_file('sha256', $referencePath) !== $page['reference_sha256']) {
                throw new \RuntimeException('Snapshot hash mismatch: '.$page['id']);
            }
            $start = microtime(true);
            try {
                $image = Image::fromFile($imagePath);
                $input = $ocr ? new ImageUrl($image->asDataUrl()) : new MessageBag(Message::ofUser(self::PROMPT, $image));
                $result = $aiPlatform->invoke($requestedModel, $input)->getResult();
                $raw = $result->getRawResult()?->getData();
                $this->write($output.'/'.$page['id'].'.raw.json', $raw ?? []);
                $content = $result->getContent();
                $text = is_string($content) ? $content : (is_object($content) && method_exists($content, 'getMarkdown') ? $content->getMarkdown() : null);
                if (!is_string($text) || '' === trim($text)) {
                    throw new \RuntimeException('No transcription returned.');
                }
                $fs->dumpFile($output.'/'.$page['id'].'.txt', $text);
                $report['pages'][] = [
                    'id' => $page['id'], 'seconds' => microtime(true) - $start,
                    'evaluation_group' => $page['evaluation_group'] ?? 'full-page',
                    'image_sha256' => $page['image_sha256'], 'reference_sha256' => $page['reference_sha256'],
                    'returned_model' => $raw['model'] ?? null, 'usage' => $raw['usage'] ?? $raw['usage_info'] ?? null,
                    'handwriting' => $page['handwriting'],
                    ...$this->metrics->score(file_get_contents($referencePath), $text),
                ];
                $io->writeln('Scored '.$page['id']);
            } catch (\Throwable $e) {
                $failed = true;
                $report['pages'][] = ['id' => $page['id'], 'error' => $e->getMessage(), 'seconds' => microtime(true) - $start];
                $io->error($page['id'].': '.$e->getMessage());
            }
            $success = array_filter($report['pages'], static fn ($p) => !isset($p['error']));
            $characters = array_sum(array_column($success, 'reference_characters'));
            $words = array_sum(array_column($success, 'reference_words'));
            $report['aggregate'] = [
                'succeeded' => count($success), 'attempted' => count($report['pages']), 'selected' => count($selected),
                'cer' => $characters ? array_sum(array_column($success, 'character_errors')) / $characters : null,
                'wer' => $words ? array_sum(array_column($success, 'word_errors')) / $words : null,
            ];
            $this->write($output.'/report.json', $report);
        }
        return $failed ? 1 : 0;
    }

    private function write(string $file, array $data): void
    {
        (new Filesystem())->dumpFile($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
    }
}
