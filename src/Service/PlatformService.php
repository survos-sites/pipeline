<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\AI\Platform\Capability;
use Symfony\AI\Platform\Message\Content\Audio;
use Symfony\AI\Platform\Message\Content\DocumentUrl;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Contracts\Service\ServiceProviderInterface;

/**
 * Browse and run AI platform tasks, rendering whatever typed result comes back.
 *
 * `app:platform` is the interactive browser over every configured `ai.platform.*`
 * and its catalog models — including OpenAI-API-compatible services registered
 * through the generic/openresponses bridges. The other commands are quick
 * shortcuts onto the same platforms. None inject API keys: each task is just
 * `$platform->invoke($model, $input)` over a configured platform.
 */
final class PlatformService
{
    /**
     * @param ServiceProviderInterface<PlatformInterface> $platforms
     */
    public function __construct(
        #[AutowireLocator('ai.platform', indexAttribute: 'name')]
        private readonly ServiceProviderInterface $platforms,
        private readonly ResultRenderer $renderer,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    #[AsCommand('app:platform', 'browse configured platforms and models, invoke one, and render the result')]
    public function platform(
        SymfonyStyle $io,
        #[Argument('platform name (interactive if omitted)')]
        ?string $platformName = null,
        #[Argument('model name (interactive if omitted)')]
        ?string $modelName = null,
        #[Argument('input text — a prompt for chat models, the text to embed for embeddings')]
        ?string $input = null,
    ): int {
        $available = array_keys($this->platforms->getProvidedServices());
        if ([] === $available) {
            $io->error('No platforms are configured (ai.platform.*).');

            return Command::FAILURE;
        }

        $platformName ??= $io->choice('Platform', $available);
        if (!$this->platforms->has($platformName)) {
            $io->error(\sprintf('Unknown platform "%s". Available: %s', $platformName, implode(', ', $available)));

            return Command::INVALID;
        }

        $platform = $this->platforms->get($platformName);
        $catalog = $platform->getModelCatalog();

        $models = array_keys($catalog->getModels());
        sort($models);
        $modelName ??= $io->choice('Model', $models);

        if (!\in_array($modelName, $models, true)) {
            $io->error(\sprintf('Unknown model "%s" for "%s". Available: %s', $modelName, $platformName, implode(', ', $models)));

            return Command::INVALID;
        }

        $payload = $this->buildInput($io, $catalog, $modelName, $input);
        if (null === $payload) {
            return Command::INVALID;
        }

        return $this->display($io, $platform->invoke($modelName, $payload)->getResult());
    }

    #[AsCommand('app:chat', 'send a prompt to a chat model and render the result')]
    public function chat(
        SymfonyStyle $io,
        #[Argument('the prompt to send')]
        string $prompt,
        #[Argument('chat model')]
        string $model = 'gpt-4o-mini',
    ): int {
        return $this->display($io, $this->platforms->get('openai')->invoke($model, new MessageBag(Message::ofUser($prompt)))->getResult());
    }

    #[AsCommand('app:embed', 'embed a string and render the vector summary')]
    public function embed(
        SymfonyStyle $io,
        #[Argument('text to embed')]
        string $text,
        #[Argument('embeddings model')]
        string $model = 'text-embedding-3-small',
    ): int {
        return $this->display($io, $this->platforms->get('openai')->invoke($model, $text)->getResult());
    }

    #[AsCommand('app:transcribe', 'transcribe an audio file with whisper and render the result')]
    public function transcribe(
        SymfonyStyle $io,
        #[Argument('path to an audio file (defaults to the bundled fixture)')]
        ?string $path = null,
        #[Argument('speech-to-text model')]
        string $model = 'whisper-1',
    ): int {
        $path ??= $this->projectDir.'/fixtures/audio.mp3';

        if (!is_readable($path)) {
            $io->error(\sprintf('Audio file not readable: %s', $path));

            return Command::INVALID;
        }

        return $this->display($io, $this->platforms->get('openai')->invoke($model, Audio::fromFile($path))->getResult());
    }

    #[AsCommand('app:ocr', 'run mistral OCR on a url and render the typed result')]
    public function ocr(
        SymfonyStyle $io,
        #[Argument('url of a pdf or image to extract text from')]
        string $url,
        #[Argument('ocr model')]
        string $model = 'mistral-ocr-latest',
    ): int {
        return $this->display($io, $this->platforms->get('mistral')->invoke($model, new DocumentUrl($url))->getResult());
    }

    /**
     * Builds the invoke() input from the chosen model's capabilities. Content-object
     * models (OCR, vision, audio) need a structured input the text browser can't supply yet.
     */
    private function buildInput(SymfonyStyle $io, ModelCatalogInterface $catalog, string $modelName, ?string $input): string|object|null
    {
        $model = $catalog->getModel($modelName);

        if ($model->supports(Capability::EMBEDDINGS)) {
            return $input ?? (string) $io->ask('Text to embed');
        }

        if ($model->supports(Capability::INPUT_MESSAGES)) {
            return new MessageBag(Message::ofUser($input ?? (string) $io->ask('Prompt')));
        }

        $io->warning(\sprintf('Model "%s" expects a structured input (document, image or audio); the interactive browser only handles text and chat models for now.', $modelName));

        return null;
    }

    private function display(SymfonyStyle $io, ResultInterface $result): int
    {
        $rendered = $this->renderer->render($result);

        $io->writeln(\sprintf('<comment>%s → %s</comment>', $rendered->meta['type'] ?? $result::class, $rendered->format));
        $io->newLine();

        // For now we print the body; a TUI would render `format: markdown` richly.
        $io->writeln($rendered->body);

        return Command::SUCCESS;
    }
}
