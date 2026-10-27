<?php

declare(strict_types=1);

namespace App\Workflow;

use Survos\ClaimsBundle\Entity\Claim;
use Survos\DataContracts\Workflow\AudioSubjectInterface;
use Survos\DataContracts\Workflow\ContextSubjectInterface;
use Survos\DataContracts\Workflow\ImageSubjectInterface;
use Survos\DataContracts\Workflow\TextSubjectInterface;
use Survos\DataContracts\Workflow\WorkflowSubjectInterface;

/** Application contracts; dependencies are evidence inputs, never implicit task execution. */
final class TaskInputResolver
{
    public const IMAGE_TASKS = ['observe', 'observe_hires', 'ocr_mistral', 'ocr_tesseract', 'htr', 'htr_annotate', 'florence', 'merit_score', 'estimate_value'];
    public const ANALYSIS_TASKS = ['generate_title', 'summarize', 'extract_metadata', 'context_description', 'fortepan_place', 'extract_census', 'periodical_structure'];

    /** @param list<Claim> $claims */
    public function resolve(string $task, WorkflowSubjectInterface $subject, array $claims = [], ?string $textClaimId = null): TaskSubject
    {
        $context = $this->context($subject, $claims);
        $image = $subject instanceof ImageSubjectInterface ? $subject->getWorkflowImageUrl() : null;
        $audio = $subject instanceof AudioSubjectInterface ? $subject->getWorkflowAudioUrl() : null;
        $text = null;

        if (in_array($task, self::IMAGE_TASKS, true)) {
            if ($image === null || trim($image) === '') {
                throw new \InvalidArgumentException("$task requires an image URL.");
            }
            if ($task !== 'ocr_mistral' && str_ends_with(strtolower(parse_url($image, PHP_URL_PATH) ?: $image), '.pdf')) {
                throw new \InvalidArgumentException("$task requires a rendered page image, not a PDF URL.");
            }
            if ($task === 'htr_annotate') {
                $text = $this->text($subject, $claims, $textClaimId, true);
                if ($text === null) {
                    throw new \InvalidArgumentException('htr_annotate requires supplied OCR text or an OCR claim.');
                }
            }
        } elseif (in_array($task, self::ANALYSIS_TASKS, true)) {
            $image = null;
            if ($task === 'periodical_structure') {
                $page = $context['periodicalPage'] ?? null;
                if (!is_array($page) || empty($page['blocks']) || empty($page['width']) || empty($page['height'])) {
                    throw new \InvalidArgumentException('periodical_structure requires explicit page blocks, width and height.');
                }
            } else {
                $text = $this->text($subject, $claims, $textClaimId);
                if ($text === null && ($task === 'extract_census' || trim((string) $context['observation_prose']) === '')) {
                    throw new \InvalidArgumentException("$task requires text evidence".($task === 'extract_census' ? '.' : ' or observation prose.'));
                }
            }
        } elseif ($task === 'transcribe_audio') {
            $image = null;
            if ($audio === null || trim($audio) === '') {
                throw new \InvalidArgumentException('transcribe_audio requires an audio URL.');
            }
        } else {
            throw new \InvalidArgumentException("No application input contract exists for task '$task'.");
        }

        // Empty values intentionally prevent the bundle from looking up claims using the wrapper class.
        $context['ocr_text'] = $text;
        $context['transcription'] = $text ?? [];
        $context['selected_text_claim_id'] = $textClaimId;
        $context['input_text_kind'] = $text === null ? 'observation' : 'transcription';
        if ($text === null && in_array($task, self::ANALYSIS_TASKS, true) && $task !== 'periodical_structure') {
            $text = $context['observation_prose'];
        }

        return new TaskSubject($subject, $context, $image, $text, $audio);
    }

    /** @param list<Claim> $claims */
    public function context(WorkflowSubjectInterface $subject, array $claims): array
    {
        $context = $subject instanceof ContextSubjectInterface ? $subject->getWorkflowContext() : [];
        $map = ['ai:observationProse' => 'observation_prose', 'dcterms:type' => 'content_type'];
        foreach ($map as $predicate => $key) {
            if (!isset($context[$key])) {
                $latest = $this->latest($subject, $claims, [$predicate]);
                $last = $latest === [] ? null : $latest[array_key_last($latest)];
                if ($last !== null && is_string($last->value)) {
                    $context[$key] = $last->value;
                }
            }
        }
        $context['observation_prose'] ??= '';

        return $context;
    }

    /** @param list<Claim> $claims */
    private function text(WorkflowSubjectInterface $subject, array $claims, ?string $selectedId, bool $ocrOnly = false): ?string
    {
        $context = $subject instanceof ContextSubjectInterface ? $subject->getWorkflowContext() : [];
        $predicates = $ocrOnly ? ['ai:ocrText'] : ['ai:ocrText', 'ai:htrText', 'ai:transcription'];
        $candidates = array_values(array_filter($this->latest($subject, $claims, $predicates),
            static fn (Claim $claim): bool => !str_starts_with($claim->source, 'htr_annotate@') && self::asText($claim->value) !== null));

        if ($selectedId !== null) {
            foreach ($candidates as $claim) {
                if ($claim->id === $selectedId) {
                    return self::asText($claim->value);
                }
            }
            throw new \InvalidArgumentException('Selected text claim is missing, superseded, annotated, or belongs to another subject.');
        }

        $supplied = $subject instanceof TextSubjectInterface ? $subject->getWorkflowText() : null;
        $supplied ??= $context['ocr_text'] ?? $context['ocrText'] ?? null;
        if (is_string($supplied) && trim($supplied) !== '') {
            return $supplied;
        }
        if (count($candidates) > 1) {
            throw new \InvalidArgumentException('Multiple text readings exist; select a text claim explicitly before continuing.');
        }

        return $candidates === [] ? null : self::asText($candidates[0]->value);
    }

    /** @param list<Claim> $claims @param list<string> $predicates @return list<Claim> */
    private function latest(WorkflowSubjectInterface $subject, array $claims, array $predicates): array
    {
        $matching = array_filter($claims, static fn (Claim $c): bool => $c->subjectType === $subject->getWorkflowSubjectType()
            && $c->subjectId === $subject->getWorkflowSubjectId() && $c->scope === $subject->getWorkflowScope()
            && in_array($c->predicate, $predicates, true));
        usort($matching, static fn (Claim $a, Claim $b): int => [$a->createdAt, $a->id] <=> [$b->createdAt, $b->id]);
        $latest = [];
        foreach ($matching as $claim) {
            $key = $claim->predicate.'|'.$claim->source;
            unset($latest[$key]);
            $latest[$key] = $claim;
        }

        return array_values($latest);
    }

    private static function asText(mixed $value): ?string
    {
        if (is_string($value)) {
            return trim($value) === '' ? null : $value;
        }

        return is_array($value) && $value !== [] ? json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) : null;
    }
}
