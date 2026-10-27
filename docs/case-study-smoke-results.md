# Live case-study smoke test — 2026-10-27

## Sources and reproduction

Import the two cases with `php bin/console app:gallery:import public/data/case-studies.json`.

- `nara-193049`: William Burnett letter, May 24, 1726. Direct NARA digital object `40-1611a.gif`, catalog https://catalog.archives.gov/id/193049. The original is downloaded by the gallery cache. The item-specific source assessment is in `config/packages/letter.yaml`.
- `ink-pythias`: “Knights of Pythias,” Audubon County Journal, June 29, 1911, page 1. Inkstory's proposed opening group TB75–TB80, sourced from LOC ALTO. `public/case-studies/inkstory/source.json` preserves the original text, group, coordinates and provenance. Its cached page raster is displayed through a CSS crop; no generated/reconstructed pixels. The supplied OCR retains its errors. This is an article opening, not an independently verified complete article.

Run the letter with `app:letter:step nara-193049 assess`, then `transcribe`, then `extract`. With the catalog assessment configured, description inference is optional and skipped from new. The live test first ran describe/AI assess, reached review because correspondence was unresolved, then resumed with the exact-item catalog assessment. The retained assessment record replaces the initial AI assessment; this limitation is not hidden by the result export.

Run the clipping with `php bin/console app:case:clipping`. It records supplied OCR, then calls existing `extract_metadata` and `summarize` tasks through GalleryTaskRunner. Reruns reuse retained records. This command is a fixed smoke-case harness, not a new recipe engine; changed source/configuration should use a new case identity. It does not advance the unrelated generic lifecycle marking.

## Observed results

Both case executions completed and their claims render in the gallery. A portable snapshot of retained outputs and usage is in `public/data/case-study-results.json`.

- Letter: describe (gpt-5.4), typed assessment, source-evidence resume, HTR (gpt-4o), metadata (gpt-4o-mini). HTR contains uncertain readings and requires human checking. Metadata largely describes appearance rather than extracting the letter's substance. Description contains an unexpected Arabic word in otherwise English prose.
- Clipping: supplied OCR, metadata and summary (gpt-4o-mini). Summary names Dr. J. M. Cole, B. I. Sallinger and Henry Engle, and Exira/Audubon. The generated title adds “Annual Meeting,” unsupported by the supplied OCR. Name normalization from damaged OCR is unverified.
- Neither metadata result produced standalone people/place claims. The case-study goal is therefore only partially met despite successful execution.
- Costs are unavailable in retained records; measured time and token usage are shown, not invented prices. Initial overwritten assessment inference is not included in retained-run totals.

No prompt tuning or architecture refactor was performed to hide these findings.

## Typewriter comparison: capture context and caption

Run `php bin/console app:case:photo` for NARA 196468. Both conditions receive 1942 (year precision) and catalog provenance; neither receives a location or orientation because these are not established. Only the second receives the NARA caption. The previous pixels-only diagnostic and previous full-metadata result remain retained. The diagnostic used earlier instructions, so it is not a controlled third arm.

Live results: capture context alone took 10,296 ms (600 input / 329 output tokens); adding the caption took 9,287 ms (653 / 357), both gpt-5.4-2026-03-05, cloud synchronous calls, cache misses. The first describes an uncertain rectangular object; the second recognizes a typewriter-like machine and connects it to the wartime collection campaign. These are single samples, not evidence of a causal timing difference. The caption can also bias what the model labels visible evidence.

The exact source URL, capture context, caption, model, mode, cache status and image fingerprint are stored in each new run and exposed in the case study. Both calls embed the same original GIF as a data URI: 487 × 600, 87,985 bytes, SHA-256 b50a8bc345ea0a55f1cd3f9af1f894ed9cc88bcda030afd1e6de08e94caed520. No app thumbnailing or monochrome conversion occurs. The original URL is provenance; the provider receives embedded bytes. Vision detail is not explicitly set. Timing covers task execution including prompt construction, provider round trip and structured mapping, excluding source download and database writes; provider/network breakdown is not measured. This instrumentation currently covers this comparison harness, not every bundle execution path.

Portable results: `public/data/typewriter-comparison.json`. Focused letter/workflow tests pass (30 tests, 139 assertions); Twig templates lint successfully.
