<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\GalleryImageRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{BinaryFileResponse, Response};
use Symfony\Component\Routing\Attribute\Route;

final class BatchController extends AbstractController
{
    #[Route('/batches', name: 'app_batches', methods: ['GET'])]
    public function index(GalleryImageRepository $images): Response
    {
        $batches = [];
        foreach ($images->findAll() as $image) {
            if ($batch = $image->legacy['batch'] ?? null) {
                $batches[$batch['id']] ??= ['id' => $batch['id'], 'collection' => $image->collection, 'count' => 0];
                ++$batches[$batch['id']]['count'];
            }
        }

        return $this->render('batch/index.html.twig', ['batches' => $batches]);
    }

    #[Route('/batches/images/{code}', name: 'app_batch_image', requirements: ['code' => '[a-f0-9]{16}'], methods: ['GET'])]
    public function image(string $code, GalleryImageRepository $images): BinaryFileResponse
    {
        $image = $images->find($code);
        $root = realpath($this->getParameter('kernel.project_dir').'/var/batch-images');
        $path = $image?->localOriginalPath ? realpath($image->localOriginalPath) : false;
        if (!$image || !isset($image->legacy['batch']) || !$root || !$path || !str_starts_with($path, $root.DIRECTORY_SEPARATOR) || !is_file($path)) {
            throw $this->createNotFoundException();
        }
        $response = new BinaryFileResponse($path);
        $response->setPrivate();
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    #[Route('/batches/{batch}', name: 'app_batch_show', requirements: ['batch' => '[a-f0-9]{16}'], methods: ['GET'])]
    public function show(string $batch, GalleryImageRepository $images): Response
    {
        $records = $images->findBatch($batch);
        if ($records === []) {
            throw $this->createNotFoundException();
        }

        return $this->render('batch/show.html.twig', ['batch' => $batch, 'images' => $records]);
    }
}
