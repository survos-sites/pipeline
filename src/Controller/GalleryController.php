<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\GalleryImageRepository;
use Survos\FieldBundle\Attribute\RouteMeta;
use Survos\FieldBundle\Enum\Audience;
use Survos\FieldBundle\Enum\Purpose;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Component\Routing\Attribute\Route;

final class GalleryController extends AbstractController
{
    #[Route('/', name: 'app_homepage')]
    #[Route('/gallery', name: 'app_gallery')]
    #[RouteMeta(
        description: 'Gallery of plain image workflow subjects imported from the manifest.',
        purpose: Purpose::List,
        label: 'Gallery',
        audience: Audience::Public,
        sitemap: true,
    )]
    #[Template('gallery.html.twig')]
    public function gallery(GalleryImageRepository $images): array
    {
        return [
            'images' => $images->findBy([], ['title' => 'ASC']),
        ];
    }
}
