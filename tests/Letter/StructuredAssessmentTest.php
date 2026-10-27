<?php

declare(strict_types=1);
namespace App\Tests\Letter;

use App\Entity\GalleryImage;
use App\Workflow\TaskSubject;
use Survos\AiWorkflowBundle\Decision\DocumentAssessment;
use Survos\AiWorkflowBundle\Task\Analysis\AssessDocumentTask;
use Survos\AiWorkflowBundle\Task\TaskClaimMapper;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Agent\Execution\ExecutionInterface;
use Symfony\AI\Platform\Metadata\Metadata;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Twig\Environment;

final class StructuredAssessmentTest extends KernelTestCase
{
    public function testClassIsPassedToSymfonyAndTypedObjectSurvivesWithoutTextParsing(): void
    {
        self::bootKernel();
        $assessment = new DocumentAssessment(true, null, true, 'Handwritten greeting and signature');
        $execution = $this->createMock(ExecutionInterface::class);
        $execution->expects(self::once())->method('asObject')->with(DocumentAssessment::class)->willReturn($assessment);
        $execution->expects(self::never())->method('getContent');
        $execution->method('getMetadata')->willReturn(new Metadata());
        $execution->method('getRawResult')->willReturn(null);
        $agent = $this->createMock(AgentInterface::class);
        $agent->expects(self::once())->method('call')->with(self::anything(), ['response_format' => DocumentAssessment::class])->willReturn($execution);
        $task = new AssessDocumentTask($agent);
        $task->setTaskServices(self::getContainer()->get(Environment::class), new MockHttpClient(), new TaskClaimMapper());
        $image = GalleryImage::fromManifestRow(['url' => 'https://example.org/letter']);
        $result = $task->run(new TaskSubject($image, [], text: 'Handwritten greeting and signature'));
        self::assertSame($assessment, $result->output);
        self::assertCount(1, $result->claims, 'Unknown printed status must not become false.');
        self::assertTrue($result->claims[0]->value);
    }

    public function testHumanAndSourceInputRejectStringBooleans(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DocumentAssessment::fromArray(['handwritten' => 'false', 'printed' => true, 'correspondence' => true, 'basis' => 'Source rule']);
    }
}
