<?php

declare(strict_types=1);

namespace App\Tests\Workflow;

use App\Workflow\TaskInputResolver;
use App\Workflow\UrlSubject;
use Survos\AiWorkflowBundle\Task\TaskRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TaskPromptWiringTest extends KernelTestCase
{
    public function testContainerUsesApplicationPromptAndRegistersCustomTask(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $task = $container->get(TaskRegistry::class)->get('summarize');
        $subject = $container->get(TaskInputResolver::class)->resolve('summarize', new UrlSubject(text: 'Exact <OCR> & text'));
        [$system] = $task->buildPrompts($subject);
        self::assertStringContainsString("<selected_transcription>\nExact <OCR> & text\n", $system);
        self::assertTrue($container->get(TaskRegistry::class)->has('estimate_value'));
    }
}
