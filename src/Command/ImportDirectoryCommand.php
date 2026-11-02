<?php

declare(strict_types=1);

namespace App\Command;

use App\Batch\LocalImageSource;
use App\Entity\GalleryImage;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Survos\AiWorkflowBundle\Task\Observation\ObserveTask;
use Survos\AiWorkflowBundle\Workflow\SubjectFlow;
use Symfony\Component\Console\Attribute\{Argument, AsCommand, Option};
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand('app:batch:import', 'Import local images through Flysystem; no AI calls during intake')]
final class ImportDirectoryCommand
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LocalImageSource $source,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Local directory containing images (recursively)')] string $directory,
        #[Option('Collection name')] string $collection = 'Inventory',
        #[Option('Validate and list files without saving')] bool $dryRun = false,
    ): int {
        if (trim($collection) === '' || mb_strlen($collection) > 255) {
            throw new \InvalidArgumentException('Collection must contain 1–255 characters.');
        }
        $batch = substr(hash('sha256', json_encode([realpath($directory), $collection], JSON_THROW_ON_ERROR)), 0, 16);
        $cacheRoot = $this->projectDir.'/var/batch-images';
        $sourceRoot = realpath($directory);
        if ($sourceRoot !== false && str_starts_with($cacheRoot.'/', rtrim($sourceRoot, '/').'/')) {
            throw new \InvalidArgumentException('Choose an image directory outside the batch cache and its parent directories.');
        }
        $storage = null;
        $created = $existing = 0;
        foreach ($this->source->images($directory) as $file) {
            $code = substr(hash('sha256', json_encode([$batch, $file['path'], $file['sha256']], JSON_THROW_ON_ERROR)), 0, 16);
            if ($this->em->find(GalleryImage::class, $code) !== null) {
                ++$existing;
                continue;
            }
            $io->writeln($file['path']);
            ++$created;
            if ($dryRun) {
                continue;
            }
            $storage ??= new Filesystem(new LocalFilesystemAdapter($cacheRoot));
            $cachePath = $code.'.'.LocalImageSource::MIME_EXTENSIONS[$file['mime']];
            $storage->write($cachePath, $file['bytes']);
            $image = new GalleryImage();
            $image->code = $code;
            $image->title = mb_substr(basename($file['path']), 0, 255);
            $image->collection = $collection;
            $image->sourceUrl = '/batches/images/'.$code;
            $image->localOriginalPath = $cacheRoot.'/'.$cachePath;
            // Mid-pipeline import deliberately bypasses automatic initial-place kickoff.
            $image->marking = SubjectFlow::PLACE_PREPARED;
            $image->addPendingStep(ObserveTask::TASK, SubjectFlow::TRANSITION_OBSERVE);
            $image->legacy['batch'] = [
                'id' => $batch, 'path' => $file['path'],
                'group' => dirname($file['path']) === '.' ? $file['path'] : dirname($file['path']),
                'sha256' => $file['sha256'], 'imported_at' => date(DATE_ATOM),
            ];
            $this->em->persist($image);
            // Each file commits separately: interrupted imports can be repeated safely.
            $this->em->flush();
        }
        $io->success(sprintf('%s%d images; %d unchanged. Batch: %s. Review at /batches/%s', $dryRun ? 'Would import ' : 'Imported ', $created, $existing, $batch, $batch));

        return 0;
    }
}
