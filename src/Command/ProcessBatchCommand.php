<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\GalleryImageRepository;
use Survos\AiWorkflowBundle\Workflow\SubjectFlow;
use Survos\WorkflowExtrasBundle\Contract\TransitionExecutorInterface;
use Symfony\Component\Console\Attribute\{Argument, AsCommand};
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Workflow\Registry;

#[AsCommand('app:batch:process', 'Run observation and analysis for a local batch (uses configured AI providers)')]
final class ProcessBatchCommand
{
    public function __construct(
        private readonly GalleryImageRepository $images,
        private readonly Registry $workflows,
        private readonly TransitionExecutorInterface $executor,
    ) {
    }

    public function __invoke(SymfonyStyle $io, #[Argument('Batch ID from app:batch:import')] string $batch): int
    {
        $images = $this->images->findBatch($batch);
        if ($images === []) {
            $io->error('Batch not found.');

            return 1;
        }
        // A local Composer demo needs no separately running queue worker.
        // Stop on errors so the operator sees the provider failure and can retry.
        $this->executor->synchronously(function () use ($images, $io): void {
            foreach ($images as $image) {
                if ($image->isWorkflowLocked()) {
                    $io->warning(sprintf('%s is locked; skipped.', $image->title));
                    continue;
                }
                $workflow = $this->workflows->get($image, SubjectFlow::WORKFLOW_NAME);
                foreach ([SubjectFlow::TRANSITION_OBSERVE, SubjectFlow::TRANSITION_ANALYZE] as $transition) {
                    if ($image->pendingCount($transition) > 0 && $workflow->can($image, $transition)) {
                        $io->writeln($image->title);
                        $this->executor->execute($workflow, $image, $transition);
                        break;
                    }
                }
            }
        });
        $io->success('Batch processing finished. Review the recorded claims at /batches/'.$batch);

        return 0;
    }
}
