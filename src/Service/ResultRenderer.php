<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\AI\Platform\Result\BinaryResult;
use Symfony\AI\Platform\Result\ChoiceResult;
use Symfony\AI\Platform\Result\ObjectResult;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\AI\Platform\Result\VectorResult;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Turns any platform/agent {@see ResultInterface} into a {@see RenderedResult}
 * a console or TUI can display. The match() routes by result type; turning a
 * typed payload into JSON is delegated to the serializer.
 *
 * This is the reusable core of a general-purpose "task debugger": given a task
 * and an input, invoke it (the platform caches it) and render whatever typed
 * result comes back — without the caller knowing the concrete result class.
 *
 * If you add symfony/ai-cache-platform, you can replace json() with
 * {@see \Symfony\AI\Platform\Bridge\Cache\ResultNormalizer}, which already
 * encodes every Result type uniformly — this class then shrinks to pure
 * display-routing (markdown vs json vs file).
 */
final class ResultRenderer
{
    public function __construct(
        private readonly NormalizerInterface $normalizer,
    ) {
    }

    public function render(ResultInterface $result): RenderedResult
    {
        return match (true) {
            $result instanceof TextResult => new RenderedResult('markdown', $result->getContent(), ['type' => TextResult::class]),
            $result instanceof ObjectResult => $this->renderObject($result),
            $result instanceof VectorResult => $this->renderVectors($result),
            $result instanceof BinaryResult => $this->renderBinary($result),
            $result instanceof ToolCallResult => new RenderedResult('json', $this->json($result->getContent()), ['type' => ToolCallResult::class]),
            $result instanceof ChoiceResult => new RenderedResult('json', $this->json(array_map(static fn (ResultInterface $r): mixed => $r->getContent(), $result->getContent())), ['type' => ChoiceResult::class]),
            default => new RenderedResult('json', $this->json($result->getContent()), ['type' => $result::class]),
        };
    }

    private function renderObject(ObjectResult $result): RenderedResult
    {
        $content = $result->getContent();

        // If the typed DTO can present itself as prose (e.g. Mistral's
        // OcrResult::getMarkdown()), render that instead of its raw JSON shape.
        if (\is_object($content) && method_exists($content, 'getMarkdown')) {
            return new RenderedResult('markdown', (string) $content->getMarkdown(), ['type' => $content::class]);
        }

        return new RenderedResult('json', $this->json($content), ['type' => \is_object($content) ? $content::class : 'array']);
    }

    private function renderVectors(VectorResult $result): RenderedResult
    {
        $vectors = $result->getContent();

        return new RenderedResult('json', $this->json([
            'count' => \count($vectors),
            'dimensions' => [] === $vectors ? null : $vectors[0]->getDimensions(),
        ]), ['type' => VectorResult::class]);
    }

    private function renderBinary(BinaryResult $result): RenderedResult
    {
        // Never dump raw bytes; save and report the path.
        $mime = $result->getMimeType();
        $ext = explode('/', $mime)[1] ?? 'bin';
        $path = sys_get_temp_dir().'/result_'.substr(hash('xxh128', $result->toBase64()), 0, 12).'.'.$ext;
        file_put_contents($path, base64_decode($result->toBase64(), true));

        return new RenderedResult('binary', $path, ['type' => BinaryResult::class, 'mime' => $mime]);
    }

    private function json(mixed $data): string
    {
        $normalized = \is_object($data) || \is_array($data) ? $this->normalizer->normalize($data) : $data;

        return (string) json_encode($normalized, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
    }
}
