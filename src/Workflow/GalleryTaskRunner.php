<?php

declare(strict_types=1);

namespace App\Workflow;

use App\Entity\GalleryImage;
use Survos\AiWorkflowBundle\Task\TaskRegistry;
use Survos\AiWorkflowBundle\Workflow\SubjectFlow;
use Survos\ClaimsBundle\Repository\ClaimRepository;
use Survos\ClaimsBundle\Service\ClaimIngestor;
use Survos\ClaimsBundle\Service\RunMeta;

/** Execute one explicit task; a failure must leave the queue and workflow milestone intact. */
final class GalleryTaskRunner
{
    public function __construct(
        private readonly TaskRegistry $tasks,
        private readonly TaskInputResolver $inputs,
        private readonly ClaimRepository $claims,
        private readonly ClaimIngestor $ingestor,
    ) {
    }

    public function runNext(GalleryImage $image, string $phase = SubjectFlow::TRANSITION_OBSERVE, ?string $textClaimId = null): ?string
    {
        if ($image->isWorkflowLocked() || ($name = $image->pendingSteps[$phase][0] ?? null) === null) {
            return null;
        }
        $task = $this->tasks->get($name);
        if ($task === null) {
            throw new \InvalidArgumentException("Task '$name' is not registered.");
        }
        $subject = $this->inputs->resolve($name, $image, $this->evidence($image), $textClaimId);
        if (!$task->supports($subject)) {
            throw new \InvalidArgumentException("Task '$name' does not support the prepared inputs.");
        }
        $started = microtime(true);
        $result = $task->run($subject);
        $meta = $result->meta;
        $this->ingestor->record(
            scope: $image->getWorkflowScope(),
            subjectType: $image->getWorkflowSubjectType(),
            subjectId: $image->getWorkflowSubjectId(),
            source: $name.'@1.0',
            rawClaims: $result->claims,
            meta: new RunMeta(
                model: $meta?->model,
                prompt: $meta?->prompt,
                response: $meta?->response,
                inputTokens: $meta?->inputTokens,
                outputTokens: $meta?->outputTokens,
                imageTokens: $meta?->imageTokens,
                durationMs: $meta?->durationMs ?? (int) round((microtime(true) - $started) * 1000),
            ),
        );

        $image->shiftPendingStep($phase);
        foreach ($result->appendTasks as $step) {
            $image->addPendingStep($step, SubjectFlow::TRANSITION_OBSERVE);
        }
        foreach ($result->appendAnalysisTasks as $step) {
            $image->addPendingStep($step, SubjectFlow::TRANSITION_ANALYZE);
        }

        return $name;
    }

    public function context(GalleryImage $image): array
    {
        return $this->inputs->context($image, $this->evidence($image));
    }

    private function evidence(GalleryImage $image): array
    {
        return $this->claims->findForSubject($image->getWorkflowSubjectType(), $image->getWorkflowSubjectId(), $image->getWorkflowScope());
    }
}
