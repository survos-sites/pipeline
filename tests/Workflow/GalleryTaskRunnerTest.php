<?php

declare(strict_types=1);

namespace App\Tests\Workflow;

use App\Command\ImportGalleryCommand;
use App\Controller\GalleryController;
use App\Entity\GalleryImage;
use App\Repository\GalleryImageRepository;
use App\Service\GalleryImageCache;
use App\Workflow\GalleryTaskRunner;
use App\Workflow\GalleryWorkflow;
use App\Workflow\TaskInputResolver;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Survos\AiWorkflowBundle\Task\TaskInterface;
use Survos\AiWorkflowBundle\Task\TaskRegistry;
use Survos\AiWorkflowBundle\Task\TaskResult;
use Survos\AiWorkflowBundle\Workflow\SubjectFlow;
use Survos\ClaimsBundle\Entity\Claim;
use Survos\ClaimsBundle\Entity\ClaimRun;
use Survos\ClaimsBundle\Repository\ClaimRepository;
use Survos\ClaimsBundle\Repository\ClaimRunRepository;
use Survos\ClaimsBundle\Service\ClaimIngestor;
use Survos\ClaimsBundle\Service\RawClaim;
use Survos\DataContracts\Workflow\WorkflowSubjectInterface;
use Survos\ImgproxyBundle\Service\ImgproxyUrlBuilder;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\Mime\MimeTypes;
use Symfony\Component\Workflow\Definition;
use Symfony\Component\Workflow\Event\TransitionEvent;
use Symfony\Component\Workflow\Marking;
use Symfony\Component\Workflow\Registry;
use Symfony\Component\Workflow\SupportStrategy\InstanceOfSupportStrategy;
use Symfony\Component\Workflow\Workflow;

final class GalleryTaskRunnerTest extends TestCase
{
    private EntityManager $em;
    private ManagerRegistry $doctrine;
    private ClaimRepository $claims;
    private ClaimIngestor $ingestor;
    private GalleryImage $image;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 2);
        $config = ORMSetup::createAttributeMetadataConfiguration([$root.'/src/Entity', $root.'/vendor/survos/claims-bundle/src/Entity'], true);
        $config->enableNativeLazyObjects(true);
        $this->em = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), $config);
        $this->doctrine = $this->createStub(ManagerRegistry::class);
        $this->doctrine->method('getManagerForClass')->willReturn($this->em);
        $this->claims = new ClaimRepository($this->doctrine);
        $this->ingestor = new ClaimIngestor($this->em, $this->claims, new ClaimRunRepository($this->doctrine));
        (new SchemaTool($this->em))->createSchema(array_map($this->em->getClassMetadata(...), [Claim::class, ClaimRun::class, GalleryImage::class]));
        $this->image = TaskInputResolverTest::image();
        $this->em->persist($this->image);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $this->em->close();
    }

    public function testOcrClaimsFeedNextAnalysisUsingTheSameIdentity(): void
    {
        $ocr = $this->task('ocr_mistral', fn (): TaskResult => new TaskResult([new RawClaim('ai:ocrText', 'Exact stored OCR')]));
        $summary = $this->task('summarize', function (WorkflowSubjectInterface $subject): TaskResult {
            self::assertSame('GalleryImage', $subject->getWorkflowSubjectType());
            self::assertSame('Exact stored OCR', $subject->getWorkflowText());
            self::assertNull($subject->getWorkflowImageUrl());

            return new TaskResult([new RawClaim('dcterms:abstract', 'Summary from OCR')]);
        });
        $runner = $this->runner(['ocr_mistral' => $ocr, 'summarize' => $summary]);
        $this->image->addPendingStep('ocr_mistral', 'observe');
        $this->image->addPendingStep('summarize', 'analyze');
        $runner->runNext($this->image);
        $this->em->flush();
        $runner->runNext($this->image, 'analyze');
        $this->em->flush();
        self::assertSame([], $this->image->pendingSteps);
        self::assertCount(2, $this->claims->findForSubject('GalleryImage', 'fixture'));
        self::assertSame([], $this->claims->findForSubject(GalleryImage::class, 'fixture'));
    }

    public function testExecutionFailureLeavesQueueAndMarkingIntact(): void
    {
        $task = $this->task('ocr_mistral', static function (): never { throw new \RuntimeException('Provider unavailable'); });
        $this->image->addPendingStep('ocr_mistral', 'observe');
        try {
            $this->runner(['ocr_mistral' => $task])->runNext($this->image);
            self::fail('Expected failure');
        } catch (\RuntimeException $e) {
            self::assertSame('Provider unavailable', $e->getMessage());
        }
        self::assertSame(['observe' => ['ocr_mistral']], $this->image->pendingSteps);
        self::assertSame('new', $this->image->getMarking());
        self::assertSame([], $this->claims->findForSubject('GalleryImage', 'fixture'));
    }

    public function testMissingDependencyDoesNotCallAgentOrConsumeQueue(): void
    {
        $task = $this->createMock(TaskInterface::class);
        $task->expects(self::never())->method('run');
        $this->image->addPendingStep('htr_annotate', 'observe');
        try {
            $this->runner(['htr_annotate' => $task])->runNext($this->image);
            self::fail('Expected dependency failure');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('requires supplied OCR text', $e->getMessage());
        }
        self::assertSame(['observe' => ['htr_annotate']], $this->image->pendingSteps);
    }

    public function testUnknownTaskRemainsQueued(): void
    {
        $this->image->addPendingStep('classify', 'observe');
        try {
            $this->runner([])->runNext($this->image);
            self::fail('Expected unknown task failure');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('not registered', $e->getMessage());
        }
        self::assertSame(['observe' => ['classify']], $this->image->pendingSteps);
    }

    public function testImportQueueIsIdempotentAndPreservesOtherPhases(): void
    {
        $this->image->addPendingStep('htr', 'observe');
        $this->image->addPendingStep('summarize', 'analyze');
        $this->em->flush();
        $manifest = tempnam(sys_get_temp_dir(), 'gallery-audit-');
        file_put_contents($manifest, json_encode([$this->image->legacy]));
        try {
            $command = new ImportGalleryCommand($this->em, new GalleryImageRepository($this->doctrine),
                new GalleryImageCache(new MockHttpClient(), new Filesystem(), new MimeTypes(), dirname(__DIR__, 2), sys_get_temp_dir()), dirname(__DIR__, 2));
            $io = new SymfonyStyle(new ArrayInput([]), new BufferedOutput());
            self::assertSame(0, $command($io, $manifest, queue: true, skipDownload: true));
            self::assertSame(0, $command($io, $manifest, queue: true, skipDownload: true));
            self::assertSame(['observe' => ['htr', 'observe'], 'analyze' => ['summarize']], $this->image->pendingSteps);
        } finally {
            unlink($manifest);
        }
    }

    public function testControllerUsesTheStoredClaimIdentity(): void
    {
        $registry = new Registry();
        $registry->addWorkflow(new Workflow(new Definition(['new'], []), new \Symfony\Component\Workflow\MarkingStore\MethodMarkingStore(true), name: SubjectFlow::WORKFLOW_NAME), new InstanceOfSupportStrategy(GalleryImage::class));
        $data = (new GalleryController())->show($this->image, $registry, new ClaimRunRepository($this->doctrine), $this->claims);
        self::assertSame('GalleryImage', $data['claimSubjectType']);
    }

    public function testObservedContentTypeIsAvailableToFallbackPolicy(): void
    {
        $this->em->persist(TaskInputResolverTest::claim('dcterms:type', 'document', 'observe@1.0'));
        $this->em->flush();
        self::assertSame('document', $this->runner([])->context($this->image)['content_type']);
        self::assertSame('Known provenance', $this->runner([])->context($this->image)['description']);
    }

    public function testWorkflowUsesFullResolutionOcrAndCurrentContentType(): void
    {
        $ocr = $this->task('ocr_mistral', function (WorkflowSubjectInterface $subject): TaskResult {
            self::assertSame('https://example.org/original.jpg', $subject->getWorkflowImageUrl());

            return new TaskResult([new RawClaim('ai:ocrText', 'OCR text'), new RawClaim('dcterms:type', 'document')]);
        });
        $tasks = ['ocr_mistral' => $ocr];
        foreach (['generate_title', 'summarize', 'extract_metadata'] as $name) {
            $tasks[$name] = $this->createStub(TaskInterface::class);
        }
        $registry = $this->registry($tasks);
        $runner = new GalleryTaskRunner($registry, new TaskInputResolver(), $this->claims, $this->ingestor);
        $workflow = new GalleryWorkflow($this->em, $runner, $registry, new ImgproxyUrlBuilder('https://images.example.org'));
        $this->image->addPendingStep('ocr_mistral', 'observe');
        $workflow->onObserve(new TransitionEvent($this->image, new Marking(['prepared' => 1])));
        self::assertNull($this->image->resolvedImageUrl);
        self::assertSame(['analyze' => ['generate_title', 'summarize', 'extract_metadata']], $this->image->pendingSteps);
    }

    private function task(string $name, callable $run): TaskInterface
    {
        $task = $this->createMock(TaskInterface::class);
        $task->method('supports')->willReturn(true);
        $task->expects(self::once())->method('run')->willReturnCallback($run);

        return $task;
    }

    private function runner(array $tasks): GalleryTaskRunner
    {
        return new GalleryTaskRunner($this->registry($tasks), new TaskInputResolver(), $this->claims, $this->ingestor);
    }

    private function registry(array $tasks): TaskRegistry
    {
        $locator = new ServiceLocator(array_map(static fn (TaskInterface $task): \Closure => static fn (): TaskInterface => $task, $tasks));
        return new TaskRegistry($locator, array_fill_keys(array_keys($tasks), TaskInterface::class));
    }
}
