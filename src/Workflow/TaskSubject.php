<?php

declare(strict_types=1);

namespace App\Workflow;

use Survos\DataContracts\Workflow\AudioSubjectInterface;
use Survos\DataContracts\Workflow\ContextSubjectInterface;
use Survos\DataContracts\Workflow\ImageSubjectInterface;
use Survos\DataContracts\Workflow\TextSubjectInterface;
use Survos\DataContracts\Workflow\WorkflowSubjectInterface;

/** An invocation's explicit inputs, retaining the original subject's claim identity. */
final readonly class TaskSubject implements WorkflowSubjectInterface, ContextSubjectInterface, ImageSubjectInterface, TextSubjectInterface, AudioSubjectInterface
{
    public function __construct(
        private WorkflowSubjectInterface $subject,
        private array $context,
        private ?string $imageUrl = null,
        private ?string $text = null,
        private ?string $audioUrl = null,
    ) {
    }

    public function getWorkflowContext(): array
    {
        return $this->context;
    }

    public function getWorkflowImageUrl(): ?string
    {
        return $this->imageUrl;
    }

    public function getWorkflowText(): ?string
    {
        return $this->text;
    }

    public function getWorkflowAudioUrl(): ?string
    {
        return $this->audioUrl;
    }

    public function getWorkflowSubjectId(): string
    {
        return $this->subject->getWorkflowSubjectId();
    }

    public function getWorkflowSubjectType(): string
    {
        return $this->subject->getWorkflowSubjectType();
    }

    public function getWorkflowScope(): ?string
    {
        return $this->subject->getWorkflowScope();
    }

    public function isWorkflowLocked(): bool
    {
        return $this->subject->isWorkflowLocked();
    }

    public function setWorkflowLocked(bool $locked): void
    {
        $this->subject->setWorkflowLocked($locked);
    }

}
