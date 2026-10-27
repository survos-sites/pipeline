<?php

declare(strict_types=1);
namespace App\Letter;

use App\Repository\GalleryImageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class LetterService
{
    public function __construct(private readonly GalleryImageRepository $images, private readonly LetterWorkflow $workflow, private readonly EntityManagerInterface $em, #[Autowire('%letter.known_decisions%')] private readonly array $knownDecisions) {}

    #[AsCommand('app:letter:step', 'Apply one explicit letter transition; omitting the transition only lists available steps.')]
    public function step(SymfonyStyle $io, #[Argument] string $code, #[Argument] ?string $transition = null): int
    {
        $image = $this->images->find($code);
        if ($image === null) { $io->error('Source not found.'); return Command::FAILURE; }
        $known = null;
        $entry = $image->legacy['letter_known_assessment'] ?? $this->knownDecisions[$code] ?? null;
        if ($entry !== null) {
            if (($entry['source_url'] ?? null) !== $image->sourceUrl || ($entry['question_revision'] ?? null) !== \Survos\AiWorkflowBundle\Decision\DocumentAssessment::REVISION) {
                throw new \InvalidArgumentException('Known decision does not match this source and question revision.');
            }
            $known = new \Survos\AiWorkflowBundle\Decision\AssessmentEvidence(
                \Survos\AiWorkflowBundle\Decision\DocumentAssessment::fromArray($entry['assessment']),
                $entry['origin'], $entry['reference']);
        }
        $subject = new LetterSubject($image, $known);
        if ($transition !== null) {
            $this->workflow->apply($subject, $transition);
            $subject->save();
            $this->em->flush();
        }
        $io->writeln('Letter state: '.$subject->marking);
        $io->listing(array_map(static fn ($t) => $t->getName(), $this->workflow->getEnabledTransitions($subject)));
        return Command::SUCCESS;
    }
    #[AsCommand('app:letter:answer', 'Record a human assessment JSON file and apply the assessment transition.')]
    public function answer(SymfonyStyle $io, #[Argument] string $code, #[Argument] string $file, #[Argument] string $reference): int
    {
        $image = $this->images->find($code);
        if ($image === null) { throw new \InvalidArgumentException('Source not found.'); }
        $json = file_get_contents($file);
        if ($json === false) { throw new \RuntimeException('Cannot read assessment file.'); }
        $assessment = \Survos\AiWorkflowBundle\Decision\DocumentAssessment::fromArray(json_decode($json, true, 512, JSON_THROW_ON_ERROR));
        $evidence = new \Survos\AiWorkflowBundle\Decision\AssessmentEvidence($assessment, 'human', $reference);
        $image->legacy['letter_known_assessment'] = ['assessment' => $assessment->jsonSerialize(), 'origin' => $evidence->origin,
            'reference' => $evidence->reference, 'source_url' => $image->sourceUrl,
            'question_revision' => \Survos\AiWorkflowBundle\Decision\DocumentAssessment::REVISION];
        return $this->step($io, $code, 'assess');
    }

}
