# Handwritten-letter prototype

The bundle owns `DocumentAssessment`, `AssessmentEvidence`, `OperationCache`, `describe_source`, and `assess_document`. The app owns the native `LetterWorkflow`, source preparation, model configuration and persistence adapter. Survos workflow attributes already extend the native Symfony attributes; this prototype does not replace that integration.

## Contract and routing

`DocumentAssessment` answers three independent questions: `handwritten`, `printed`, and `correspondence`. Each is `true`, `false`, or `null` (unknown). `basis` is required. Model confidence is not fabricated for human or deterministic answers.

The AI task passes `DocumentAssessment::class` as `response_format`, consumes `asObject(DocumentAssessment::class)`, and retains the object in `TaskResult::output`. Structured task results never enter the Markdown stripping / JSON parsing fallback. Existing claim mappers receive the DTO's array representation only after hydration. Tasks without a response class retain their existing text path.

Normal route: `describe → assess → transcribe → extract → complete`.
A trusted answer permits `assess` directly from `new`, skipping both description and assessment inference. HTR requires handwritten=true AND correspondence=true; all other assessments enable `request_review`. A human answer can return `needs_review` to `assessed`. The printed answer is retained for other workflow policies; this prototype does not add an OCR branch.

Guards read stored objects and never call a model. Follow-up task lists returned by old extraction tasks are ignored: the declared workflow owns routing.

## Use

From the app, link local bundle sources with `../mono/link .` (the script links all matching Survos packages). No Composer repository override is required.

```sh
# Read-only: list the next transitions.
php bin/console app:letter:step c17846c2
# Explicit calls; API credentials and reachable providers are required.
php bin/console app:letter:step c17846c2 describe
php bin/console app:letter:step c17846c2 assess
php bin/console app:letter:step c17846c2 transcribe
php bin/console app:letter:step c17846c2 extract
```

`config/packages/letter.yaml` supplies models and trusted source answers. AI agent model configuration references the same parameters used by cache identity. Change `configuration_revision` when changing provider/endpoint or inference defaults; floating model aliases have a 24-hour cache lifetime. Prefer pinned model versions for reproducible runs.

For a trusted source/harvest rule, add an entry under `letter.known_decisions`, keyed by gallery code:

```yaml
letter.known_decisions:
    c17846c2:
        source_url: 'EXACT URL FROM THE IMPORTED RECORD'
        question_revision: document-assessment-v1
        origin: source # source, human, or tool
        reference: 'collection-rule:series-specific-rule-v1'
        assessment:
            handwritten: true
            printed: false
            correspondence: true
            basis: 'The named collection contract identifies this as a handwritten letter.'
```

This is an example, not a factual rule applied to NARA. Source and question revisions must match; title keywords are not automatically trusted. Source rules should be scoped to the collection/series that actually guarantees the fact.

Humans supply the same answer as JSON:

```json
{"handwritten":true,"printed":false,"correspondence":true,"basis":"Reviewed the original letter image."}
```

```sh
php bin/console app:letter:answer c17846c2 /path/to/answer.json 'review:operator-123'
```

This records provenance and applies the assessment transition. It does not transcribe or run inference. Allowed when `assess` is available (new, described, or needs_review); it does not silently overwrite a completed case. Jev/Clef can later adapt their answers into this DTO; the letter prototype now uses class-based structured output rather than a second decision SDK contract.

## Caching and records

Successful task results are cached for 24 hours using source bytes, explicit context, rendered prompts, output schema, model and configuration revision, and subject identity. Exceptions and invalid required outputs are not cached. The bytes sent as the image input are the bytes hashed. A retry can reuse a successful inference after a persistence failure.

Claims use the stable `GalleryImage` identity and `letter:<task>@1` sources. Each step records cache-hit status, original timing/tokens, current elapsed time, and decision origin/reference. Cache hits report zero new inference tokens, retaining original usage separately. No billed cost is invented. Existing claims storage replaces earlier runs for the same source; it is not a complete historical ledger.

Prototype state and execution traces live in `GalleryImage.legacy.letter_workflow`; known human evidence lives in `letter_known_assessment`. Run one operator/worker per source: concurrent execution and full immutable history are not implemented. Source image changes should create a new case/reset with freshly verified evidence, rather than resume an old assessment. Cached local originals are snapshots, not automatic remote refreshes.

## Verification

Tests use fake AI and an isolated SQLite database, covering full execution, typed response consumption, exact transcription input, guards, source/human bypass, failure/retry, cache reuse and cache invalidation. No fake claims are inserted into the demo database.

## Later improvement

Replace verbose `SubjectFlow` queue expressions with named subject accessors (e.g. `subject.observeTransitionCount > 0`), preserving behavior. This prototype leaves those shared lifecycle guards unchanged.
