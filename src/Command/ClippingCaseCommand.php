<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\GalleryImageRepository;
use App\Workflow\GalleryTaskRunner;
use Doctrine\ORM\EntityManagerInterface;
use Survos\ClaimsBundle\Repository\ClaimRunRepository;
use Survos\ClaimsBundle\Service\ClaimIngestor;
use Survos\ClaimsBundle\Service\RawClaim;
use Survos\ClaimsBundle\Service\RunMeta;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Reproducible smoke case using recorded Inkstory OCR and existing analysis tasks. */
#[AsCommand('app:case:clipping', 'Analyze a sourced clipping using its supplied OCR; resume retained successful steps.')]
final readonly class ClippingCaseCommand
{
    public function __construct(
        private GalleryImageRepository $images,
        private GalleryTaskRunner $runner,
        private ClaimIngestor $ingestor,
        private ClaimRunRepository $runs,
        private EntityManagerInterface $em,
    ) {}

    public function __invoke(SymfonyStyle $io, #[Argument] string $code = 'ink-pythias'): int
    {
        $image = $this->images->find($code) ?? throw new \InvalidArgumentException('Case not imported.');
        $text = $image->legacy['source_ocr'] ?? null;
        if (!is_string($text) || trim($text) === '') {
            throw new \InvalidArgumentException('This case requires recorded source OCR.');
        }
        $criteria = ['subjectType' => $image->getWorkflowSubjectType(), 'subjectId' => $code, 'scope' => null];
        if (!$this->runs->findOneBy($criteria + ['source' => 'source:inkstory@1'])) {
            $this->ingestor->record(null, $image->getWorkflowSubjectType(), $code, 'source:inkstory@1',
                [new RawClaim('ai:ocrText', $text, 100, 'Supplied LOC ALTO OCR via Inkstory; accuracy not independently scored.')],
                new RunMeta(inputTokens: 0, outputTokens: 0, durationMs: 0, response: [
                    'origin' => 'source', 'citation' => $image->legacy['citation_url'],
                    'blocks' => $image->legacy['source_blocks'], 'sha256' => hash('sha256', $text),
                ]));
            $this->em->flush();
        }
        foreach (['extract_metadata', 'summarize'] as $task) {
            if ($this->runs->findOneBy($criteria + ['source' => $task.'@1.0'])) {
                $io->writeln($task.': retained result');
                continue;
            }
            if ($image->pendingCount('analyze') === 0) {
                $image->addPendingStep($task, 'analyze');
            }
            if (($image->pendingSteps['analyze'][0] ?? null) !== $task) {
                throw new \LogicException('Another analysis task is pending; inspect the queue first.');
            }
            $this->runner->runNext($image, 'analyze');
            $this->em->flush();
            $io->writeln($task.': recorded');
        }
        $image->legacy['case_execution'] = ['status' => 'complete', 'tasks' => ['source:inkstory', 'extract_metadata', 'summarize']];
        $this->em->flush();
        $io->success('Clipping case complete.');
        return 0;
    }
}
