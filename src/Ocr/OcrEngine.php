<?php

declare(strict_types=1);

namespace App\Ocr;

interface OcrEngine
{
    public function id(): string;

    public function label(): string;

    /** False when the engine is not configured on this machine; it is then skipped, not failed. */
    public function isAvailable(): bool;

    /** Paid engines run only when the user ticks the box; an upload never spends money by itself. */
    public function isPaid(): bool;

    /** @param non-empty-string $path an image file on disk */
    public function recognize(string $path, string $mimeType): OcrResult;
}
