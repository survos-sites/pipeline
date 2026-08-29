<?php

declare(strict_types=1);

namespace App\Workflow;

use Survos\DataContracts\Workflow\ContextSubjectInterface;
use Survos\DataContracts\Workflow\ImageSubjectInterface;
use Survos\DataContracts\Workflow\TextSubjectInterface;
use Survos\DataContracts\Workflow\WorkflowSubjectInterface;
use Survos\StateBundle\Traits\MarkingInterface;
use Survos\StateBundle\Traits\MarkingTrait;

/**
 * An ad-hoc, non-persisted workflow subject wrapping a single URL and/or some text.
 *
 * `ai:task:run` runs a task against a persisted entity loaded from the database; this
 * is the throwaway equivalent for the `app:tasks` TUI, where the only input is a URL
 * (image/document task) or pasted text (text/analysis task). It satisfies just enough
 * of the subject contract to run — no entity, no persistence, no marking flow.
 *
 * For text/analysis tasks the pasted text is also surfaced through context (e.g. under
 * `observation_prose`) so a task that *consumes* a prior-claim predicate can be tested
 * by standing that text in for the dependency it would normally read from the database.
 *
 * @author Tac Tacelosky <tacman@gmail.com>
 */
final class UrlSubject implements WorkflowSubjectInterface, ImageSubjectInterface, TextSubjectInterface, ContextSubjectInterface, MarkingInterface
{
    use MarkingTrait;

    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        private readonly ?string $url = null,
        private readonly ?string $text = null,
        private readonly array $context = [],
    ) {
    }

    public function getWorkflowImageUrl(): ?string
    {
        return $this->url;
    }

    public function getWorkflowText(): ?string
    {
        return $this->text;
    }

    public function getWorkflowContext(): array
    {
        return $this->context;
    }

    public function getWorkflowSubjectId(): string
    {
        return 'adhoc';
    }

    public function getWorkflowSubjectType(): string
    {
        return 'adhoc';
    }

    public function getWorkflowScope(): ?string
    {
        return null;
    }

    public function isWorkflowLocked(): bool
    {
        return false;
    }

    public function setWorkflowLocked(bool $locked): void
    {
    }
}
