<?php

declare(strict_types=1);

namespace App\Ocr;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Calls any server that speaks Mistral's POST /v1/ocr: Mistral itself, or the
 * local rusto service (~/sites/ocr-api). Only the base URL and key differ.
 */
final class MistralCompatibleEngine implements OcrEngine
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $id,
        private readonly string $label,
        private readonly string $baseUrl,
        private readonly string $apiKey = '',
        private readonly string $model = 'mistral-ocr-latest',
        private readonly bool $requiresKey = false,
        private readonly bool $paid = false,
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function isAvailable(): bool
    {
        return '' !== $this->baseUrl && (!$this->requiresKey || '' !== $this->apiKey);
    }

    public function isPaid(): bool
    {
        return $this->paid;
    }

    public function recognize(string $path, string $mimeType): OcrResult
    {
        $started = hrtime(true);
        try {
            $response = $this->http->request('POST', rtrim($this->baseUrl, '/').'/v1/ocr', [
                'headers' => '' !== $this->apiKey ? ['Authorization' => 'Bearer '.$this->apiKey] : [],
                'json' => [
                    'model' => $this->model,
                    'document' => [
                        'type' => 'image_url',
                        'image_url' => sprintf('data:%s;base64,%s', $mimeType, base64_encode((string) file_get_contents($path))),
                    ],
                ],
                'timeout' => 120,
            ]);
            $pages = $response->toArray()['pages'] ?? [];
            $text = implode("\n\n", array_map(static fn (array $p): string => (string) ($p['markdown'] ?? ''), $pages));

            return new OcrResult($this->id, $this->label, $text, $this->elapsed($started));
        } catch (\Throwable $e) {
            return new OcrResult($this->id, $this->label, '', $this->elapsed($started), $e->getMessage());
        }
    }

    private function elapsed(int $started): int
    {
        return (int) round((hrtime(true) - $started) / 1e6);
    }
}
