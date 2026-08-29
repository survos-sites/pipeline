<?php

declare(strict_types=1);

namespace App\Tests;

use App\Workflow\UrlSubject;
use PHPUnit\Framework\Attributes\DataProvider;
use Survos\AiWorkflowBundle\Task\TaskRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Runs each task that declares `samples` against a live endpoint.
 *
 * The point is to call the real services with a tiny known input and assert a task
 * actually produces claims. CI gets no API keys, so paid tasks (OpenAI/Mistral) skip
 * automatically — only genuinely free/self-hosted tasks run live there. Locally, with
 * keys present, every sampled task runs for real.
 *
 * @author Tac Tacelosky <tacman@gmail.com>
 */
final class TaskSampleTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function sampleProvider(): iterable
    {
        self::bootKernel();
        $registry = self::getContainer()->get(TaskRegistry::class);
        \assert($registry instanceof TaskRegistry);

        foreach (array_keys($registry->getTaskMap()) as $name) {
            $descriptor = $registry->getDescriptor($name);
            foreach ($descriptor?->samples ?? [] as $i => $sample) {
                yield \sprintf('%s#%d', $name, $i) => [$name, (string) $sample];
            }
        }

        self::ensureKernelShutdown();
    }

    #[DataProvider('sampleProvider')]
    public function testTaskAgainstSample(string $taskName, string $sample): void
    {
        self::bootKernel();
        $registry = self::getContainer()->get(TaskRegistry::class);
        \assert($registry instanceof TaskRegistry);

        $subject = str_starts_with($sample, 'http')
            ? new UrlSubject(url: $sample)
            : new UrlSubject(text: $sample, context: ['observation_prose' => $sample, 'text' => $sample]);

        try {
            $task = $registry->get($taskName);
            self::assertNotNull($task, \sprintf('Task "%s" is not registered.', $taskName));
            $result = $task->run($subject);
        } catch (\Throwable $e) {
            if ($this->isMissingCredentials($e)) {
                self::markTestSkipped(\sprintf('%s needs an API key not present in this environment.', $taskName));
            }

            throw $e;
        }

        self::assertNotEmpty($result->claims, \sprintf('%s produced no claims for its sample.', $taskName));
    }

    private function isMissingCredentials(\Throwable $e): bool
    {
        $haystack = strtolower($e->getMessage().' '.$e::class);
        foreach (['environment variable not found', 'authentication', 'unauthorized', '401', 'api_key', 'api key', 'invalid_api_key'] as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }
}
