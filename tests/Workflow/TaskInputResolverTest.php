<?php

declare(strict_types=1);

namespace App\Tests\Workflow;

use App\Entity\GalleryImage;
use App\Task\EstimateValueTask;
use App\Workflow\TaskInputResolver;
use App\Workflow\UrlSubject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Survos\AiWorkflowBundle\Task\AbstractPromptTask;
use Survos\AiWorkflowBundle\Task\Analysis\ContextDescriptionTask;
use Survos\AiWorkflowBundle\Task\Analysis\ExtractMetadataTask;
use Survos\AiWorkflowBundle\Task\Analysis\GenerateTitleTask;
use Survos\AiWorkflowBundle\Task\Analysis\SummarizeTask;
use Survos\AiWorkflowBundle\Task\Observation\AnnotateHandwritingTask;
use Survos\AiWorkflowBundle\Task\TaskClaimMapper;
use Survos\ClaimsBundle\Entity\Claim;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Agent\Execution\ExecutionInterface;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\UserMessage;
use Symfony\AI\Platform\Metadata\Metadata;
use Symfony\Component\HttpClient\MockHttpClient;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class TaskInputResolverTest extends TestCase
{
    public static function analysisTasks(): iterable
    {
        yield [SummarizeTask::class, ['summary' => 'A summary']];
        yield [GenerateTitleTask::class, ['title' => 'A title']];
        yield [ExtractMetadataTask::class, ['description' => 'Metadata description']];
        yield [ContextDescriptionTask::class, ['description' => 'Context description']];
    }

    #[DataProvider('analysisTasks')]
    public function testExactSelectedTextReachesAgentWithoutImage(string $class, array $response): void
    {
        $text = "Dear <Alice> & Bob,\nThe amount is £17.\n";
        $image = self::image();
        $claim = self::claim('ai:ocrText', $text);
        $prepared = (new TaskInputResolver())->resolve($class::TASK, $image, [$claim], $claim->id);
        $agent = $this->createMock(AgentInterface::class);
        $agent->expects(self::once())->method('call')->willReturnCallback(function (MessageBag $messages, array $options) use ($text, $response): ExecutionInterface {
            $prompt = $messages->getSystemMessage()->getContent();
            self::assertStringContainsString($text, $prompt);
            self::assertStringContainsString('Known provenance', $prompt);
            foreach ($messages->getMessages() as $message) {
                if ($message instanceof UserMessage) {
                    self::assertFalse($message->hasImageContent());
                }
            }
            $result = $this->createStub(ExecutionInterface::class);
            $dto = (new \Symfony\AI\Platform\StructuredOutput\Serializer())->denormalize($response, $options['response_format']);
            $result->method('asObject')->willReturn($dto);
            $result->method('getMetadata')->willReturn(new Metadata());
            $result->method('getRawResult')->willReturn(null);

            return $result;
        });
        $task = new $class($agent);
        self::configurePrompts($task);
        self::assertTrue($task->supports($prepared));
        self::assertNotEmpty($task->run($prepared)->claims);
        self::assertSame('GalleryImage', $prepared->getWorkflowSubjectType());
    }

    public function testAnnotationReceivesOcrAndRetainsImage(): void
    {
        $text = 'Printed <text> & handwritten additions';
        $prepared = (new TaskInputResolver())->resolve('htr_annotate', self::image(), [self::claim('ai:ocrText', $text)]);
        $task = new AnnotateHandwritingTask();
        self::configurePrompts($task);
        self::assertStringContainsString($text, implode("\n", $task->buildPrompts($prepared)));
        self::assertSame('https://example.org/original.jpg', $prepared->getWorkflowImageUrl());
    }

    public function testAnnotationCannotUseHtrAsItsOcrInput(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new TaskInputResolver())->resolve('htr_annotate', self::image(), [self::claim('ai:htrText', 'Handwriting', 'htr@1.0')]);
    }

    public function testAmbiguousReadingsRequireSelection(): void
    {
        $this->expectExceptionMessage('Multiple text readings');
        (new TaskInputResolver())->resolve('summarize', self::image(), [self::claim('ai:ocrText', 'OCR'), self::claim('ai:htrText', 'HTR', 'htr@1.0')]);
    }

    public function testSelectionUsesExactReadingAndNeverAnnotatedVariant(): void
    {
        $ocr = self::claim('ai:ocrText', 'OCR');
        $htr = self::claim('ai:htrText', 'HTR', 'htr@1.0');
        $annotation = self::claim('ai:htrText', '<hw>HTR</hw>', 'htr_annotate@1.0');
        $resolver = new TaskInputResolver();
        self::assertSame('HTR', $resolver->resolve('summarize', self::image(), [$ocr, $htr, $annotation], $htr->id)->getWorkflowText());
        $this->expectExceptionMessage('Selected text claim');
        $resolver->resolve('summarize', self::image(), [$annotation], $annotation->id);
    }

    public function testMissingEvidenceCannotBeReplacedByImage(): void
    {
        $this->expectExceptionMessage('requires text evidence');
        (new TaskInputResolver())->resolve('summarize', self::image());
    }

    public function testObservationOnlyAnalysisHasTextInputAndNoClaimLookup(): void
    {
        $prepared = (new TaskInputResolver())->resolve('summarize', self::image(), [self::claim('ai:observationProse', 'Two people beside a building.', 'observe@1.0')]);
        $task = new SummarizeTask();
        self::configurePrompts($task);
        self::assertTrue($task->supports($prepared));
        $prompt = implode("\n", $task->buildPrompts($prepared));
        self::assertStringContainsString('Two people beside a building.', $prompt);
        self::assertStringNotContainsString('<selected_transcription>', $prompt);
        self::assertNull($prepared->getWorkflowImageUrl());
    }

    public function testClaimsFromOtherIdentityOrScopeAreNotConsumed(): void
    {
        $this->expectExceptionMessage('requires text evidence');
        (new TaskInputResolver())->resolve('summarize', self::image(), [
            new Claim(null, GalleryImage::class, 'fixture', 'ai:ocrText', 'ocr@1', 'wrong type'),
            new Claim(null, 'GalleryImage', 'other', 'ai:ocrText', 'ocr@1', 'wrong item'),
            new Claim('other-scope', 'GalleryImage', 'fixture', 'ai:ocrText', 'ocr@1', 'wrong scope'),
        ]);
    }

    public function testDirectTextWinsWithoutTrimmingOrReinterpretingIt(): void
    {
        $text = "  Original spelling\n";
        $prepared = (new TaskInputResolver())->resolve('summarize', new UrlSubject(text: $text));
        self::assertSame($text, $prepared->getWorkflowText());
    }

    public function testPdfIsNotSilentlySentAsTextOnlyHandwritingRequest(): void
    {
        $subject = new UrlSubject(url: 'https://example.org/page.pdf');
        self::assertNotNull((new TaskInputResolver())->resolve('ocr_mistral', $subject)->getWorkflowImageUrl());
        $this->expectExceptionMessage('rendered page image');
        (new TaskInputResolver())->resolve('htr', $subject);
    }

    public function testLayoutIsAnExplicitInputNotInferredFromAnOcrString(): void
    {
        $this->expectExceptionMessage('page blocks, width and height');
        (new TaskInputResolver())->resolve('periodical_structure', new UrlSubject(text: 'newspaper text'));
    }

    public function testStaleEstimateTaskNowLoadsAndProducesAClaim(): void
    {
        $agent = $this->createMock(AgentInterface::class);
        $result = $this->createStub(ExecutionInterface::class);
        $result->method('getContent')->willReturn('{"estimate_low":10,"estimate_high":20,"currency":"USD","rationale":"Visible wear"}');
        $result->method('getMetadata')->willReturn(new Metadata());
        $result->method('getRawResult')->willReturn(null);
        $agent->expects(self::once())->method('call')->willReturn($result);
        $task = new EstimateValueTask($agent);
        self::configurePrompts($task);
        $claims = $task->run((new TaskInputResolver())->resolve('estimate_value', self::image()))->claims;
        self::assertSame('ai:valueEstimate', $claims[0]->predicate);
        self::assertSame(10, $claims[0]->value['estimate_low']);
    }

    public static function image(): GalleryImage
    {
        return GalleryImage::fromManifestRow(['code' => 'fixture', 'url' => 'https://example.org/original.jpg', 'title' => 'Known item', 'metadata' => ['description' => 'Known provenance', 'date' => '1901']]);
    }

    public static function claim(string $predicate, mixed $value, string $source = 'ocr_mistral@1.0'): Claim
    {
        return new Claim(null, 'GalleryImage', 'fixture', $predicate, $source, $value);
    }

    private static function configurePrompts(AbstractPromptTask $task): void
    {
        $root = dirname(__DIR__, 2);
        $loader = new FilesystemLoader($root.'/templates');
        $loader->addPath($root.'/templates/bundles/SurvosAiWorkflowBundle', 'SurvosAiWorkflow');
        $loader->addPath($root.'/vendor/survos/ai-workflow-bundle/templates', 'SurvosAiWorkflow');
        $task->setTaskServices(new Environment($loader, ['strict_variables' => true, 'autoescape' => 'html']), new MockHttpClient(), new TaskClaimMapper());
    }
}
