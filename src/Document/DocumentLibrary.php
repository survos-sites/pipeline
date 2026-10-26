<?php

declare(strict_types=1);

namespace App\Document;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** @author Tac Tacelosky <tacman@gmail.com> */
final class DocumentLibrary
{
    public function __construct(#[Autowire('%kernel.project_dir%')] private readonly string $projectDir)
    {
    }

    public function get(string $code): array
    {
        $entries = json_decode(file_get_contents($this->projectDir.'/var/private/documents.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($entries as $entry) {
            if (($entry['code'] ?? null) === $code && isset($entry['document_dir'])) {
                return $entry;
            }
        }
        throw new \InvalidArgumentException('Unknown document.');
    }

    public function directory(string $code): string
    {
        $entry = $this->get($code);
        $directory = realpath($this->projectDir.'/var/private/'.$entry['document_dir']);
        if (false === $directory || !str_starts_with($directory, $this->projectDir.'/var/private/documents/')) {
            throw new \RuntimeException('Document directory is unavailable.');
        }
        return $directory;
    }

    public function transcript(string $code): array
    {
        $file = $this->directory($code).'/transcript.json';
        if (!is_file($file)) {
            throw new \RuntimeException('The transcript has not been prepared yet.');
        }
        return json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
    }

    public function context(string $code): string
    {
        $transcript = $this->transcript($code);
        if ([] === $transcript['pages']) {
            throw new \RuntimeException('No transcribed pages are available yet.');
        }
        $coverage = $transcript['complete'] ? 'All reading pages have machine OCR.' :
            'PARTIAL TRANSCRIPT: only reading pages '.implode(', ', array_column($transcript['pages'], 'number')).' are available. Explicitly qualify summaries as covering this sample only. Never claim absence from the whole diary.';
        return $coverage."\n\n".implode("\n\n", array_map(
            static fn (array $page): string => sprintf("[Scan %d, %s; reading page %d]\n%s", $page['scan'], $page['side'], $page['number'], $page['markdown']),
            $transcript['pages'],
        ));
    }
}
