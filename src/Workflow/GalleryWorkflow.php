<?php

declare(strict_types=1);

namespace App\Workflow;

use App\Entity\GalleryImage;
use Doctrine\ORM\EntityManagerInterface;
use Survos\AiWorkflowBundle\Task\ObserveTask;
use Survos\AiWorkflowBundle\Task\TaskRunner;
use Survos\AiWorkflowBundle\Workflow\SubjectFlow;
use Symfony\Component\Workflow\Attribute\AsTransitionListener;
use Symfony\Component\Workflow\Event\TransitionEvent;

final class GalleryWorkflow
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TaskRunner $taskRunner,
    ) {
    }

    #[AsTransitionListener(SubjectFlow::WORKFLOW_NAME, SubjectFlow::TRANSITION_PREPARE)]
    public function onPrepare(TransitionEvent $event): void
    {
        $image = $this->getImage($event);
        $image->addPendingStep(ObserveTask::TASK);

        $this->entityManager->persist($image);
        $this->entityManager->flush();
    }

    #[AsTransitionListener(SubjectFlow::WORKFLOW_NAME, SubjectFlow::TRANSITION_OBSERVE)]
    public function onObserve(TransitionEvent $event): void
    {
        $image = $this->getImage($event);
        $this->taskRunner->runNext($image);
        $this->entityManager->persist($image);
        $this->entityManager->flush();
    }

    private function getImage(TransitionEvent $event): GalleryImage
    {
        $image = $event->getSubject();
        \assert($image instanceof GalleryImage);

        return $image;
    }
}
