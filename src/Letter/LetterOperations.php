<?php

declare(strict_types=1);
namespace App\Letter;

use App\Service\GalleryImageCache;
use App\Workflow\TaskSubject;
use Survos\AiWorkflowBundle\Decision\DocumentAssessment;
use Survos\AiWorkflowBundle\Execution\OperationCache;
use Survos\AiWorkflowBundle\Execution\CachedExecution;
use Survos\AiWorkflowBundle\Task\TaskRegistry;
use Survos\AiWorkflowBundle\Task\TaskResult;
use Survos\ClaimsBundle\Service\ClaimIngestor;
use Survos\ClaimsBundle\Service\RunMeta;
use Survos\ClaimsBundle\Service\RawClaim;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class LetterOperations
{
    public function __construct(
        private readonly TaskRegistry $tasks,
        private readonly OperationCache $cache,
        private readonly ClaimIngestor $claims,
        private readonly GalleryImageCache $images,
        #[Autowire('%letter.models%')] private readonly array $models,
    ) {}

    public function describe(LetterSubject $subject): void
    {
        $subject->description = $this->text($this->task($subject, 'describe_source', 'dcterms:description'), 'dcterms:description');
    }
    public function assess(LetterSubject $subject): void
    {
        if ($subject->knownDecision !== null) {
            $known = $subject->knownDecision;
            $assessment = $known->assessment;
            $execution = new CachedExecution($assessment, 'evidence_'.hash('sha256', json_encode([$assessment, $known->origin, $known->reference], JSON_THROW_ON_ERROR)), false, 0, 0, date(DATE_ATOM));
            $this->record($subject, 'assess_document', $execution, [new RawClaim('ai:documentAssessment', $assessment->jsonSerialize())],
                new RunMeta(inputTokens: 0, outputTokens: 0, response: ['assessment' => $assessment, 'origin' => $known->origin, 'reference' => $known->reference]));
        } else {
            $assessment = $this->task($subject, 'assess_document')->output;
            if (!$assessment instanceof DocumentAssessment) { throw new \UnexpectedValueException('Expected DocumentAssessment output.'); }
        }
        $subject->assessment = $assessment;
    }

    public function transcribe(LetterSubject $subject): void
    {
        $subject->transcription = $this->text($this->task($subject, 'htr', 'ai:htrText'), 'ai:htrText');
    }
    public function extract(LetterSubject $subject): void { $this->task($subject, 'extract_metadata'); }

    private function task(LetterSubject $subject, string $name, ?string $requiredText = null): TaskResult
    {
        $this->images->cacheOriginal($subject->image);
        $path = $subject->image->localOriginalPath;
        if ($path === null || !is_file($path)) { throw new \RuntimeException('A cached original is required.'); }
        // Send precisely the bytes identified by the cache key, never a mutable remote URL.
        $bytes = file_get_contents($path);
        if ($bytes === false) { throw new \RuntimeException('Cannot read the source.'); }
        $digest = hash('sha256', $bytes);
        $imageUrl = 'data:'.(new \finfo(FILEINFO_MIME_TYPE))->file($path).';base64,'.base64_encode($bytes);
        $textOnly = in_array($name, ['assess_document', 'extract_metadata'], true);
        $input = new TaskSubject($subject->image, [
            'title' => $subject->image->title,
            'description' => $subject->description,
            'transcription' => $subject->transcription,
            'input_text_kind' => 'transcription',
            'observation_prose' => $subject->description,
        ], imageUrl: $textOnly ? null : $imageUrl, text: $textOnly ? ($name === 'assess_document' ? $subject->description : $subject->transcription) : null);
        $task = $this->tasks->get($name);
        if ($task === null || !$task->supports($input)) { throw new \RuntimeException('Task does not support the supplied inputs.'); }
        $execution = $this->cache->execute([
            'operation' => $name.'@letter-v2',
            'schema' => $task->outputClass() ? (new \Symfony\AI\Platform\StructuredOutput\ResponseFormatFactory())->create($task->outputClass()) : null, 'subject' => $subject->image->code,
            'content' => $digest, 'context' => $input->getWorkflowContext(),
            'prompts' => $task->buildPrompts($input), 'model_configuration' => $this->models[$name], 'configuration_revision' => $this->models['configuration_revision'],
        ], function () use ($task, $input, $requiredText, $name): TaskResult {
            $result = $task->run($input);
            if ($requiredText !== null) { $this->text($result, $requiredText); }
            if ($name === 'assess_document' && !$result->output instanceof DocumentAssessment) { throw new \UnexpectedValueException('Assessment task must return a DocumentAssessment.'); }
            if ($result->claims === [] && $name !== 'assess_document') { throw new \UnexpectedValueException('Extraction produced no claims.'); }
            return $result;
        });
        $result = $execution->value;
        // Follow-up task lists are deliberately not executed: the workflow owns the graph.
        $claims = $result->claims;
        if ($result->output instanceof DocumentAssessment) { $claims[] = new RawClaim('ai:documentAssessment', $result->output->jsonSerialize()); }
        $this->record($subject, $name, $execution, $claims, $result->meta);
        return $result;
    }
    private function text(TaskResult $result, string $predicate): string
    {
        foreach ($result->claims as $claim) {
            if ($claim->predicate === $predicate && is_string($claim->value) && trim($claim->value) !== '') { return $claim->value; }
        }
        throw new \UnexpectedValueException('Required text evidence was not returned.');
    }
    private function record(LetterSubject $subject, string $name, CachedExecution $execution, array $claims, ?RunMeta $meta): void
    {
        $trace = ['task' => $name, 'cache_key' => $execution->key, 'cache_hit' => $execution->cacheHit,
            'elapsed_ms' => $execution->elapsedMs, 'original_duration_ms' => $execution->originalDurationMs,
            'original_created_at' => $execution->createdAt, 'policy_revision' => 'letter-v2', 'answer_origin' => $meta?->response['origin'] ?? 'ai',
            'evidence_reference' => $meta?->response['reference'] ?? null, 'model' => $meta?->model, 'original_input_tokens' => $meta?->inputTokens,
            'original_output_tokens' => $meta?->outputTokens];
        $this->claims->record(scope: null, subjectType: $subject->image->getWorkflowSubjectType(), subjectId: $subject->image->code,
            source: 'letter:'.$name.'@1', rawClaims: $claims,
            meta: new RunMeta(model: $meta?->model, prompt: $meta?->prompt,
                response: ['execution' => $trace, 'output' => $meta?->response],
                inputTokens: $execution->cacheHit ? 0 : $meta?->inputTokens,
                outputTokens: $execution->cacheHit ? 0 : $meta?->outputTokens,
                durationMs: $execution->elapsedMs));
        $subject->executions[] = $trace;
    }
}
