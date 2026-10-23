<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand('app:benchmark:import', 'Freeze consecutive completed FromThePage pages, images and human reference text')]
final class BenchmarkImportCommand
{
    public function __construct(private readonly HttpClientInterface $http) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Public FromThePage /display/ page URL')] string $url,
        #[Argument('New output directory (never overwrites a snapshot)')] string $directory,
        #[Option('Number of consecutive pages, 1–20')] int $count = 6,
    ): int {
        if ($count < 1 || $count > 20 || file_exists($directory)) {
            throw new \InvalidArgumentException('Use a new directory and a count between 1 and 20.');
        }
        $this->validateUrl($url);
        $fs = new Filesystem();
        $fs->mkdir($directory);
        $work = preg_replace('~/display/\d+$~', '', $url);
        $about = $this->fetch($work.'/about');
        $fs->dumpFile($directory.'/rights.html', $about);
        $aboutText = html_entity_decode(strip_tags($about));
        if (!preg_match('/Permission Description\s*CC BY 2\.0\s*Author Name/u', $aboutText)) {
            throw new \RuntimeException('Expected explicit work-level CC BY 2.0 statement; inspect rights.html. No images downloaded.');
        }
        $manifest = [
            'schema_version' => 1, 'fetched_at' => gmdate(DATE_ATOM),
            'work_url' => $work, 'rights_url' => $work.'/about',
            'license' => 'https://creativecommons.org/licenses/by/2.0/',
            'reference_policy' => 'FromThePage Complete status; human-reviewed reference, not infallible ground truth. HTML snapshot is authoritative for provenance; dateModified is not assumed to be a revision ID.',
            'pages' => [],
        ];
        for ($i = 1; $i <= $count; ++$i) {
            $this->validateUrl($url);
            $html = $this->fetch($url);
            $doc = new \DOMDocument();
            @$doc->loadHTML($html);
            $xpath = new \DOMXPath($doc);
            $json = $xpath->query('//script[@type="application/ld+json"]')->item(0)?->textContent;
            $data = json_decode($json ?? '', true, flags: JSON_THROW_ON_ERROR);
            $status = trim($xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " page_status ")]')->item(0)?->textContent ?? '');
            if ('Status: Complete' !== $status || !is_string($data['text'] ?? null) || '' === trim($data['text'])) {
                throw new \RuntimeException('Page has no completed reference: '.$url);
            }
            if (!preg_match('/tileSources:\s*(\[[^\n]+\])/', $html, $match)) {
                throw new \RuntimeException('Image viewer source missing.');
            }
            $tiles = json_decode($match[1], true, flags: JSON_THROW_ON_ERROR);
            $tile = is_string($tiles[0]) ? json_decode($tiles[0], true, flags: JSON_THROW_ON_ERROR) : $tiles[0];
            $imageUrl = $tile['url'] ?? '';
            $this->validateUrl($imageUrl);
            $image = $this->fetch($imageUrl);
            $info = getimagesizefromstring($image);
            $extension = match ($info['mime'] ?? '') {
                'image/jpeg' => 'jpg', 'image/png' => 'png',
                default => throw new \RuntimeException('Expected a JPEG or PNG image.'),
            };
            $id = basename(parse_url($url, PHP_URL_PATH));
            $fs->mkdir($directory.'/'.$id);
            $fs->dumpFile($directory.'/'.$id.'/source.html', $html);
            $fs->dumpFile($directory.'/'.$id.'/image.'.$extension, $image);
            $fs->dumpFile($directory.'/'.$id.'/reference.txt', $data['text']);
            $manifest['pages'][] = [
                'id' => $id, 'label' => $data['name'] ?? $id, 'url' => $url,
                'image_url' => $imageUrl, 'image' => $id.'/image.'.$extension,
                'reference' => $id.'/reference.txt', 'status' => $status,
                'source_date_modified' => $data['dateModified'] ?? null,
                'image_sha256' => hash('sha256', $image),
                'reference_sha256' => hash('sha256', $data['text']),
                'source_html_sha256' => hash('sha256', $html),
                'handwriting' => 'not yet visually assessed',
            ];
            $fs->dumpFile($directory.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
            $io->writeln('Saved '.$id.' ('.$info[0].' × '.$info[1].')');
            $next = $xpath->query('//a[contains(concat(" ", normalize-space(@class), " "), " page-nav_next ")]')->item(0)?->getAttribute('href');
            if ($i < $count && !$next) {
                throw new \RuntimeException('No next page.');
            }
            $url = str_starts_with($next ?? '', '/') ? 'https://fromthepage.com'.$next : ($next ?? '');
        }
        $io->success('Snapshot saved. Visually assess handwriting and reference coverage before choosing scored pages.');
        return 0;
    }

    private function fetch(string $url): string
    {
        return $this->http->request('GET', $url, ['timeout' => 60, 'max_duration' => 120])->getContent();
    }

    private function validateUrl(string $url): void
    {
        if ('https' !== parse_url($url, PHP_URL_SCHEME) || !in_array(parse_url($url, PHP_URL_HOST), ['fromthepage.com', 'www.fromthepage.com'], true)) {
            throw new \InvalidArgumentException('Expected an HTTPS FromThePage URL.');
        }
    }
}
