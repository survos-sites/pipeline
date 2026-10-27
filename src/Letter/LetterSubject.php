<?php

declare(strict_types=1);
namespace App\Letter;

use App\Entity\GalleryImage;
use Survos\AiWorkflowBundle\Decision\AssessmentEvidence;
use Survos\AiWorkflowBundle\Decision\DocumentAssessment;

/** Prototype state is persisted separately from the gallery lifecycle in legacy.letter_workflow. */
final class LetterSubject
{
    public string $marking = 'new';
    public string $description = '';
    public string $transcription = '';
    public ?DocumentAssessment $assessment = null;
    public array $executions = [];
    public function __construct(public readonly GalleryImage $image, public readonly ?AssessmentEvidence $knownDecision = null)
    {
        $state = $image->legacy['letter_workflow'] ?? [];
        $this->marking = $state['marking'] ?? 'new';
        $this->description = $state['description'] ?? '';
        $this->transcription = $state['transcription'] ?? '';
        $this->assessment = isset($state['assessment']) ? DocumentAssessment::fromArray($state['assessment']) : null;
        $this->executions = $state['executions'] ?? [];
    }
    public function getMarking(): string { return $this->marking; }
    public function setMarking(string $marking, array $context = []): void { $this->marking = $marking; }
    public function hasKnownDecision(): bool { return $this->knownDecision !== null; }
    public function canTranscribe(): bool { return $this->assessment?->handwritten === true && $this->assessment?->correspondence === true; }
    public function save(): void
    {
        $this->image->legacy['letter_workflow'] = [
            'marking' => $this->marking, 'description' => $this->description,
            'transcription' => $this->transcription, 'assessment' => $this->assessment?->jsonSerialize(),
            'executions' => $this->executions,
        ];
    }
}
