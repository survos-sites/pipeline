<?php

declare(strict_types=1);
namespace App\Letter;

use Symfony\Component\Workflow\Attribute\AsWorkflow;
use Symfony\Component\Workflow\Attribute\Transition;
use Symfony\Component\Workflow\Attribute\AsTransitionListener;
use Symfony\Component\Workflow\Event\TransitionEvent;
use Symfony\Component\Workflow\WorkflowTrait;

#[AsWorkflow(name: 'handwritten_letter', supports: LetterSubject::class, initialMarking: 'new')]
final class LetterWorkflow
{
    use WorkflowTrait;

    #[Transition(from: 'new', to: 'described', guard: 'not subject.hasKnownDecision()')]
    public const DESCRIBE = 'describe';
    #[Transition(from: 'needs_review', to: 'assessed', guard: 'subject.hasKnownDecision()')]
    #[Transition(from: 'new', to: 'assessed', guard: 'subject.hasKnownDecision()')]
    #[Transition(from: 'described', to: 'assessed', guard: "subject.description != ''")]
    public const ASSESS = 'assess';
    #[Transition(from: 'assessed', to: 'transcribed', guard: 'subject.canTranscribe()')]
    public const TRANSCRIBE = 'transcribe';
    #[Transition(from: 'assessed', to: 'needs_review', guard: 'not subject.canTranscribe()')]
    public const REVIEW = 'request_review';
    #[Transition(from: 'transcribed', to: 'complete', guard: "subject.transcription != ''")]
    public const EXTRACT = 'extract';

    public function __construct(private readonly LetterOperations $operations) {}

    #[AsTransitionListener(transition: self::DESCRIBE)]
    public function describe(TransitionEvent $event): void { $this->operations->describe($event->getSubject()); }
    #[AsTransitionListener(transition: self::ASSESS)]
    public function assess(TransitionEvent $event): void { $this->operations->assess($event->getSubject()); }
    #[AsTransitionListener(transition: self::TRANSCRIBE)]
    public function transcribe(TransitionEvent $event): void { $this->operations->transcribe($event->getSubject()); }
    #[AsTransitionListener(transition: self::EXTRACT)]
    public function extract(TransitionEvent $event): void { $this->operations->extract($event->getSubject()); }
}
