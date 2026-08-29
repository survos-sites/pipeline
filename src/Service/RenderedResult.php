<?php

declare(strict_types=1);

namespace App\Service;

/**
 * A medium-agnostic rendering of a platform/agent result.
 *
 * The renderer decides WHAT to show (markdown, JSON, a saved file, a vector
 * summary); a console, a TUI, or an HTTP response decides HOW to show it.
 */
final readonly class RenderedResult
{
    /**
     * @param 'markdown'|'json'|'text'|'vector'|'binary' $format
     * @param array<string, mixed>                       $meta
     */
    public function __construct(
        public string $format,
        public string $body,
        public array $meta = [],
    ) {
    }
}
