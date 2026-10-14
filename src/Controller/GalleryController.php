<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\GalleryImage;
use Survos\AiWorkflowBundle\Workflow\SubjectFlow;
use Survos\WorkflowExtrasBundle\Contract\TransitionExecutorInterface;
use Symfony\Component\Workflow\Registry;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
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

    #[Route('/gallery/{galleryImageId}', name: 'app_gallery_show')]
    #[RouteMeta(
        description: 'Display a gallery image workflow subject with AI claims.',
        purpose: Purpose::Show,
        label: 'Gallery Image',
        audience: Audience::Public,
        sitemap: false,
    )]
    #[Template('gallery/show.html.twig')]
    public function show(GalleryImage $image, Registry $registry): array
    {
        return [
            'image' => $image,
            'transitions' => $registry->get($image, SubjectFlow::WORKFLOW_NAME)->getEnabledTransitions($image),
            'claimSubjectType' => GalleryImage::class,
            'claimSubjectId' => $image->code,
        ];
    }

    #[Route('/gallery/{galleryImageId}/transition/{transition}', name: 'app_gallery_transition', methods: ['POST'])]
    public function transition(GalleryImage $image, string $transition, Request $request, Registry $registry, TransitionExecutorInterface $executor): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('gallery_transition_'.$image->code, $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid transition token.');
        }
        $workflow = $registry->get($image, SubjectFlow::WORKFLOW_NAME);
        if (!$workflow->can($image, $transition)) {
            throw $this->createAccessDeniedException('Transition is not currently enabled.');
        }
        $executor->execute($workflow, $image, $transition);

        return $this->redirectToRoute('app_gallery_show', ['galleryImageId' => $image->code]);
    }
}
