# Task execution contracts

The gallery uses `App\Workflow\GalleryTaskRunner`, not the bundle's generic
queue runner. `TaskInputResolver` prepares a `TaskSubject` for each invocation.
The existing task tester uses the same resolver for prompt preview and execution.
These are application adapters; installed Composer packages are unchanged.

## Identity and evidence

Gallery claims retain the existing `GalleryImage` subject type. The controller,
claim reader, and writer all use `getWorkflowSubjectType()` and the subject ID.
The resolver checks scope as well, including a null scope. It supplies hydrated
context to bundle tasks, preventing lookups against the prepared wrapper's class.
Existing claims under another subject-type key are not silently merged.

Known catalog metadata is passed separately from observation prose and selected
transcription. Imported manifest `pipeline` arrays do not become executable queues.

## Required inputs

| Tasks | Required input |
| --- | --- |
| observe, observe_hires, htr, florence, ocr_tesseract, merit_score, estimate_value | Image URL |
| ocr_mistral | Image or PDF URL |
| htr_annotate | Image URL and supplied OCR text or an ai:ocrText claim |
| summarize, generate_title, extract_metadata, context_description, fortepan_place | Selected text or observation prose; no image |
| extract_census | Selected text; no image |
| periodical_structure | Explicit periodicalPage blocks, width, and height; no image |
| transcribe_audio | Audio URL |

A PDF must be rendered into page images before a vision/HTR task can read it.
The gallery supplies the original URL for OCR and handwriting; observation can
use the thumbnail. Dependencies are checked, not automatically executed.
New tasks require an explicit application input contract before using this runner.

## Choosing text

Explicitly supplied text wins unless a specific claim ID is requested. With one
stored reading, the resolver uses it. With multiple OCR/HTR/transcription readings,
it stops and requires selection instead of deciding silently. It keeps the newest
claim per predicate/source, and rejects annotated HTR as a plain transcription.

Pass `textClaimId` to `GalleryTaskRunner::runNext()` or `text_claim_id` in the
workflow transition context. A selection must belong to the same subject and
scope, and still be the current claim for that source. No selection UI is added.

Analysis prompts include the exact selected text, preserving spelling, whitespace,
and markup characters. Observation-only analysis is labelled as observation
rather than a document transcription. Prompts treat source material as data.

## Failure and queue behavior

A missing registration, contract, input, unsupported subject, or execution error
throws before removing the task. The transition must not advance on such an error;
normal worker logging/retry handling receives the exception. The runner consumes
a task only after execution and claim recording succeed. The application flushes
claims before evaluating content-based defaults.

`app:gallery:import --queue` appends one observe task in the observation phase.
It preserves existing tasks and other phases, and repeated imports deduplicate it.

## Deferred

The legacy presets, retired manifest-runner documentation/CI, and new demo UI are
not rebuilt in this change. Bundle-owned generic task commands do not use these
application contracts; use the gallery runner for persisted gallery execution.
Do not expose those generic commands as a substitute for the prepared execution
path when building the later UI.
