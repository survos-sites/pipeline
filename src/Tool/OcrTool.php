<?php

declare(strict_types=1);

namespace App\Tool;

use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\AI\Platform\Message\Content\DocumentUrl;
use Symfony\AI\Platform\Message\Content\ImageUrl;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The Mistral OCR capability, exposed as a model-facing tool.
 *
 * The same underlying capability shows up three ways in this app, one per TUI:
 *  - a platform capability  — `app:capabilities` → mistral-ocr-latest / input-pdf
 *  - a curated engine task   — `app:tasks` → ocr_mistral (code-facing, structured output)
 *  - a tool (this class)      — `app:tools` → ocr (model-facing, callable by an agent/MCP)
 *
 * Tools are the model-facing projection of a capability; tasks are the code-facing one.
 * An engine like this one normally has tasks and no tools — a tool only earns its keep
 * once a model, not your code, decides when to call it.
 *
 * @author Tac Tacelosky <tacman@gmail.com>
 */
#[AsTool('ocr', 'Extract text as Markdown from a document or image at the given URL.')]
final class OcrTool
{
    public function __construct(
        #[Autowire(service: 'ai.platform.mistral')]
        private readonly PlatformInterface $platform,
    ) {
    }

    public function __invoke(string $url): string
    {
        $document = $this->isPdf($url) ? new DocumentUrl($url) : new ImageUrl($url);

        return $this->platform->invoke('mistral-ocr-latest', $document)->asObject()->getMarkdown();
    }

    private function isPdf(string $url): bool
    {
        return str_ends_with(strtolower(parse_url($url, \PHP_URL_PATH) ?: $url), '.pdf');
    }
}
