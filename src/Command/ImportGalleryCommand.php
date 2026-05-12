<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\GalleryImage;
use App\Repository\GalleryImageRepository;
use App\Service\GalleryImageCache;
use Doctrine\ORM\EntityManagerInterface;
use Survos\AiWorkflowBundle\Task\Observation\ObserveTask;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand('app:gallery:import', 'Import plain image gallery rows into workflow subjects')]
final class ImportGalleryCommand
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly GalleryImageRepository $images,
        private readonly GalleryImageCache $imageCache,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('manifest path, relative to project root')] string $manifest = 'public/data/images.json',
        #[Option('replace current workflow queues with the default observe task queue')] bool $queue = false,
        #[Option('skip caching source images locally for AI tools')] bool $skipDownload = false,
    ): int {
        $path = str_starts_with($manifest, '/') ? $manifest : $this->projectDir . '/' . $manifest;
        if (!is_file($path)) {
            $io->error(sprintf('Manifest not found: %s', $path));

            return Command::FAILURE;
        }

        $rows = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($rows)) {
            $io->error('Manifest must contain a JSON array.');

            return Command::FAILURE;
        }

        $created = 0;
        $updated = 0;
        $cached = 0;
        $cacheFailures = 0;
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['url'])) {
                continue;
            }

            $code = (string) ($row['code'] ?? substr(sha1((string) $row['url']), 0, 8));
            $image = $this->images->find($code);
            if ($image === null) {
                $image = GalleryImage::fromManifestRow($row);
                $this->em->persist($image);
                ++$created;
            } else {
                $image->updateFromManifestRow($row);
                ++$updated;
            }

            if ($queue) {
                $image->addPendingStep(ObserveTask::TASK);
            }

            if (!$skipDownload) {
                try {
                    $this->imageCache->cacheOriginal($image);
                    ++$cached;
                } catch (\RuntimeException $e) {
                    ++$cacheFailures;
                    $io->warning(sprintf('Could not cache %s: %s', $image->code, $e->getMessage()));
                }
            }
        }

        $this->em->flush();

        $io->success(sprintf(
            'Imported %d gallery images (%d created, %d updated, %d cached, %d cache failures).',
            $created + $updated,
            $created,
            $updated,
            $cached,
            $cacheFailures,
        ));

        return Command::SUCCESS;
    }
}
