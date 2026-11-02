<?php

declare(strict_types=1);

namespace App\Tests\Batch;

use App\Command\ImportDirectoryCommand;
use App\Entity\GalleryImage;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;

final class LocalBatchKernelTest extends KernelTestCase
{
    public function testIntakeDoesNotDispatchAndReviewRoutesRender(): void
    {
        $previousEnv = $_ENV['DATABASE_URL'] ?? null;
        $previousServer = $_SERVER['DATABASE_URL'] ?? null;
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        $folder = sys_get_temp_dir().'/batch-kernel-'.bin2hex(random_bytes(6));
        mkdir($folder);
        file_put_contents($folder.'/label.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aE9sAAAAASUVORK5CYII='));
        $cached = null;
        try {
            $kernel = self::bootKernel();
            $container = self::getContainer();
            $factories = [];
            foreach (['observe', 'generate_title', 'summarize'] as $name) {
                $task = $this->createMock(\Survos\AiWorkflowBundle\Task\TaskInterface::class);
                $task->method('supports')->willReturn(true);
                $task->expects(self::once())->method('run')->willReturnCallback(static function ($subject) use ($name) {
                    if ($name === 'observe') {
                        self::assertStringStartsWith('data:image/png;base64,', $subject->getWorkflowImageUrl());
                    } else {
                        self::assertNull($subject->getWorkflowImageUrl());
                    }

                    return new \Survos\AiWorkflowBundle\Task\TaskResult([
                        new \Survos\ClaimsBundle\Service\RawClaim($name === 'observe' ? 'ai:observationProse' : 'ai:'.$name, 'A wheelchair with a readable label'),
                    ]);
                });
                $factories[$name] = static fn () => $task;
            }
            $container->set(\Survos\AiWorkflowBundle\Task\TaskRegistry::class,
                new \Survos\AiWorkflowBundle\Task\TaskRegistry(new \Symfony\Component\DependencyInjection\ServiceLocator($factories), array_combine(array_keys($factories), array_keys($factories))));
            $em = $container->get('doctrine')->getManager();
            (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
            $command = $container->get(ImportDirectoryCommand::class);
            $command(new SymfonyStyle(new ArrayInput([]), new BufferedOutput()), $folder, 'Local smoke test');
            $image = $em->getRepository(GalleryImage::class)->findOneBy(['collection' => 'Local smoke test']);
            $cached = $image->localOriginalPath;
            foreach (['prepare', 'observe', 'analyze', 'review', 'publish'] as $phase) {
                self::assertCount(0, $container->get('messenger.transport.ai_subject.'.$phase)->getSent());
            }
            $process = $container->get(\App\Command\ProcessBatchCommand::class);
            $io = new SymfonyStyle(new ArrayInput([]), new BufferedOutput());
            self::assertSame(0, $process($io, $image->legacy['batch']['id']));
            self::assertSame(0, $process($io, $image->legacy['batch']['id']));
            self::assertSame(0, $image->pendingCount('observe'));
            self::assertSame(0, $image->pendingCount('analyze'));
            foreach (['/batches', '/batches/'.$image->legacy['batch']['id'], '/gallery/'.$image->code, '/batches/images/'.$image->code] as $url) {
                $response = $kernel->handle(Request::create($url));
                self::assertSame(200, $response->getStatusCode(), $url.' '.substr((string) $response->getContent(), 0, 300));
            }
        } finally {
            self::ensureKernelShutdown();
            $_ENV['DATABASE_URL'] = $previousEnv;
            $_SERVER['DATABASE_URL'] = $previousServer;
            (new Filesystem())->remove(array_filter([$folder, $cached]));
        }
    }
}
