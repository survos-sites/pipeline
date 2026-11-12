<?php

declare(strict_types=1);

namespace App\Ocr;

final readonly class OcrResult
{
    public function __construct(
        public string $engineId,
        public string $label,
        public string $text = '',
        public int $milliseconds = 0,
        public ?string $error = null,
    ) {
    }

    public function characters(): int
    {
        return mb_strlen($this->text);
    }
}
