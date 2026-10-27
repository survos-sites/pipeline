<?php

declare(strict_types=1);
namespace App\Tests\Letter;

use App\Entity\GalleryImage;
use App\Letter\{LetterOperations, LetterSubject, LetterWorkflow};
use App\Service\GalleryImageCache;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\{EntityManager, ORMSetup};
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Survos\AiWorkflowBundle\Decision\DocumentAssessment;
use Survos\AiWorkflowBundle\Execution\OperationCache;
use Survos\AiWorkflowBundle\Task\{AbstractPromptTask, TaskRegistry, TaskResult};
use Survos\ClaimsBundle\Entity\{Claim, ClaimRun};
use Survos\ClaimsBundle\Repository\{ClaimRepository, ClaimRunRepository};
use Survos\ClaimsBundle\Service\{ClaimIngestor, RawClaim, RunMeta};
use Symfony\AI\Platform\Bridge\TypeSafe\Factory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mime\MimeTypes;

final class LetterExecutionTest extends KernelTestCase
{
    public function testFullWorkflowAndReplayUseExactInputsAndRecordCacheHits(): void
    {
        self::bootKernel();
        $root = dirname(__DIR__, 2);
        $config = ORMSetup::createAttributeMetadataConfig([$root.'/src/Entity', $root.'/vendor/survos/claims-bundle/src/Entity'], true);
        $config->enableNativeLazyObjects(true);
        $em = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), $config);
        (new SchemaTool($em))->createSchema(array_map($em->getClassMetadata(...), [Claim::class, ClaimRun::class, GalleryImage::class]));
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($em);
        $claims = new ClaimRepository($registry);
        $ingestor = new ClaimIngestor($em, $claims, new ClaimRunRepository($registry));
        $cache = new OperationCache(new ArrayAdapter());
        $file = tempnam(sys_get_temp_dir(), 'letter-test-');
        file_put_contents($file, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j3ioAAAAASUVORK5CYII='));
        $image = GalleryImage::fromManifestRow(['code' => 'letter-test', 'url' => 'https://example.org/letter', 'title' => 'Letter']);
        $image->localOriginalPath = $file;
        $em->persist($image);
        $em->flush();
        $calls = [];
        $factories = [];
        foreach (['describe_source' => ['dcterms:description', 'Handwritten lines on a letter'], 'assess_document' => ['ai:documentAssessment', ['handwritten' => true, 'printed' => false, 'correspondence' => true, 'basis' => 'Visible letter']], 'htr' => ['ai:htrText', 'Dear Ada, meet me in London.'], 'extract_metadata' => ['foaf:Person', 'Ada']] as $name => [$predicate, $value]) {
            $task = $this->getMockBuilder(AbstractPromptTask::class)->disableOriginalConstructor()->onlyMethods(['run', 'supports', 'buildPrompts'])->getMock();
            $task->method('supports')->willReturn(true);
            $task->method('buildPrompts')->willReturn(['Versioned system prompt', 'User prompt']);
            $task->expects(self::exactly($name === 'htr' ? 2 : 1))->method('run')->willReturnCallback(function ($input) use (&$calls, $name, $predicate, $value) {
                $calls[] = $name;
                if ($name === 'htr' && count(array_filter($calls, static fn ($n) => $n === 'htr')) === 1) { throw new \RuntimeException('Temporary model failure'); }
                if ($name === 'extract_metadata') {
                    self::assertSame('Dear Ada, meet me in London.', $input->getWorkflowText());
                    self::assertNull($input->getWorkflowImageUrl());
                } elseif ($name === 'assess_document') { self::assertSame('Handwritten lines on a letter', $input->getWorkflowText()); self::assertNull($input->getWorkflowImageUrl()); } else { self::assertStringStartsWith('data:image/png;base64,', $input->getWorkflowImageUrl()); }
                return new TaskResult(output: $name === 'assess_document' ? DocumentAssessment::fromArray($value) : null, claims: [new RawClaim($predicate, $value)], appendTasks: ['must_never_run'], meta: new RunMeta(model: 'fake-model', inputTokens: 100, outputTokens: 10));
            });
            $factories[$name] = static fn () => $task;
        }
        
        $operations = new LetterOperations(new TaskRegistry(new ServiceLocator($factories), array_combine(array_keys($factories), array_keys($factories))), $cache, $ingestor,
            new GalleryImageCache(new MockHttpClient(), new Filesystem(), new MimeTypes(), $root, sys_get_temp_dir()),
            ['assess_document' => 'fake', 'describe_source' => 'fake', 'htr' => 'fake', 'extract_metadata' => 'fake', 'configuration_revision' => 'test']);
        self::getContainer()->set(LetterOperations::class, $operations);
        $workflow = self::getContainer()->get(LetterWorkflow::class);
        try {
            for ($replay = 0; $replay < 2; ++$replay) {
                $subject = new LetterSubject($image);
                foreach (['describe', 'assess', 'transcribe', 'extract'] as $transition) {
                    self::assertTrue($workflow->can($subject, $transition));
                    if ($transition === 'transcribe' && $replay === 0) {
                        try { $workflow->apply($subject, $transition); self::fail('Expected failure'); }
                        catch (\RuntimeException $e) { self::assertSame('Temporary model failure', $e->getMessage()); }
                        self::assertSame('assessed', $subject->marking);
                        self::assertSame('', $subject->transcription);
                    }
                    $workflow->apply($subject, $transition);
                    $em->flush();
                }
                self::assertSame('complete', $subject->marking);
                self::assertCount(4, $subject->executions);
                self::assertSame($replay === 1, $subject->executions[3]['cache_hit']);
            }
            self::assertSame(['describe_source', 'assess_document', 'htr', 'htr', 'extract_metadata'], $calls);
            self::assertCount(5, $claims->findForSubject('GalleryImage', 'letter-test'));
            $human = new \Survos\AiWorkflowBundle\Decision\AssessmentEvidence(new DocumentAssessment(true, false, true, 'Reviewed original'), 'human', 'review:123');
            $subject = new LetterSubject($image, $human);
            $subject->marking = 'needs_review';
            $workflow->apply($subject, 'assess');
            $em->flush();
            self::assertSame('assessed', $subject->marking);
            self::assertTrue($workflow->can($subject, 'transcribe'));
            self::assertSame('human', $subject->executions[0]['answer_origin']);
            self::assertSame(0, $subject->executions[0]['original_input_tokens']);
        } finally { unlink($file); $em->close(); }
    }
}
