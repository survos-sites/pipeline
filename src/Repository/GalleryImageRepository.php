<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\GalleryImage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GalleryImage>
 */
final class GalleryImageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GalleryImage::class);
    }
    /** @return list<GalleryImage> */
    public function findBatch(string $batch): array
    {
        // Keep the first local demo portable across SQLite and PostgreSQL.
        return array_values(array_filter($this->findBy([], ['title' => 'ASC']),
            static fn (GalleryImage $image): bool => ($image->legacy['batch']['id'] ?? null) === $batch));
    }
}
