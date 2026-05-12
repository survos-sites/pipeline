<?php

declare(strict_types=1);

namespace App\Workflow;

use App\Entity\GalleryImage;
use Doctrine\ORM\EntityManagerInterface;
use Survos\AiWorkflowBundle\Task\Observation\AnnotateHandwritingTask;
use Survos\AiWorkflowBundle\Task\Observation\ObserveHiresTask;
use Survos\AiWorkflowBundle\Task\Observation\ObserveTask;
use Survos\AiWorkflowBundle\Task\Observation\TranscribeHandwritingTask;
use Survos\AiWorkflowBundle\Task\TaskRegistry;
use Survos\AiWorkflowBundle\Task\TaskRunner;
use Survos\AiWorkflowBundle\Workflow\SubjectFlow;
use Survos\DataContracts\Metadata\ContentType;
use Survos\ImgproxyBundle\Service\ImgproxyUrlBuilder;
use Symfony\Component\Workflow\Attribute\AsTransitionListener;
use Symfony\Component\Workflow\Event\TransitionEvent;

final class GalleryWorkflow
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TaskRunner $taskRunner,
        private readonly TaskRegistry $taskRegistry,
        private readonly ImgproxyUrlBuilder $imgproxy,
    ) {
    }

    #[AsTransitionListener(SubjectFlow::WORKFLOW_NAME, SubjectFlow::TRANSITION_PREPARE)]
    public function onPrepare(TransitionEvent $event): void
    {
        $image = $this->getImage($event);
        $image->addPendingStep(ObserveTask::TASK, SubjectFlow::TRANSITION_OBSERVE);

        $this->entityManager->persist($image);
        $this->entityManager->flush();
    }

    #[AsTransitionListener(SubjectFlow::WORKFLOW_NAME, SubjectFlow::TRANSITION_OBSERVE)]
    public function onObserve(TransitionEvent $event): void
    {
        $image = $this->getImage($event);

        $nextTask = $image->pendingSteps[SubjectFlow::TRANSITION_OBSERVE][0] ?? null;
        $image->resolvedImageUrl = match ($nextTask) {
            ObserveHiresTask::TASK,
            TranscribeHandwritingTask::TASK,
            AnnotateHandwritingTask::TASK => $this->resolveHighResUrl($image),
            default                       => $this->resolveThumbnailUrl($image),
        };

        $this->taskRunner->runNext($image);

        $image->resolvedImageUrl = null;

        // If observe produced no analysis tasks, apply content-type defaults
        if ($image->pendingCount(SubjectFlow::TRANSITION_OBSERVE) === 0
            && $image->pendingCount(SubjectFlow::TRANSITION_ANALYZE) === 0
        ) {
            $contentType = $image->getWorkflowContext()['content_type'] ?? null;
            foreach ($this->defaultAnalysisTasks($contentType) as $task) {
                if ($this->taskRegistry->has($task)) {
                    $image->addPendingStep($task, SubjectFlow::TRANSITION_ANALYZE);
                }
            }
        }

        $this->entityManager->persist($image);
        $this->entityManager->flush();
    }

    #[AsTransitionListener(SubjectFlow::WORKFLOW_NAME, SubjectFlow::TRANSITION_ANALYZE)]
    public function onAnalyze(TransitionEvent $event): void
    {
        $image = $this->getImage($event);

        $this->taskRunner->runNext($image, SubjectFlow::TRANSITION_ANALYZE);

        $this->entityManager->persist($image);
        $this->entityManager->flush();
    }

    private function getImage(TransitionEvent $event): GalleryImage
    {
        $image = $event->getSubject();
        \assert($image instanceof GalleryImage);

        return $image;
    }

    /**
     * TODO: replace with DTO-driven task selection once data-contracts
     *       DTOs are annotated with #[AsAnalysisTask] or similar.
     */
    private function defaultAnalysisTasks(?string $contentType): array
    {
        $tasks = ['generate_title'];

        $tasks[] = 'summarize';

        if (in_array($contentType, [
            ContentType::NEWSPAPER,
            ContentType::MANUSCRIPT,
            ContentType::CORRESPONDENCE,
            ContentType::MAP,
            ContentType::DOCUMENT,
        ], true)) {
            $tasks[] = 'extract_metadata';
        }

        return $tasks;
    }

    private function resolveThumbnailUrl(GalleryImage $image): ?string
    {
        if (!str_starts_with((string) $image->sourceUrl, 'http')) {
            return null;
        }

        return $this->imgproxy->aiThumbnail($image->sourceUrl);
    }

    /**
     * TODO: use imgproxy large preset once mediary is wired up.
     */
    private function resolveHighResUrl(GalleryImage $image): ?string
    {
        return str_starts_with((string) $image->sourceUrl, 'http') ? $image->sourceUrl : null;
    }
}
