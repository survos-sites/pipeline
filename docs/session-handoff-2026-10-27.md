# Session handoff — 2026-10-27

## Checkpoint

Stop implementation here. Next session should follow `docs/publication-plan.md`, not start a broad workflow refactor. The intended audience is Museado clients: real sources, goals, useful results, and optional technical detail. Nothing was deployed or pushed by this session.

## Saved work

- Pipeline `218649f`: curated gallery containing NARA typewriter photo (`nara-196468`), handwritten letter (`nara-193049`), and Inkstory clipping (`ink-pythias`); retained results, source assets, input provenance and publication plan.
- Pipeline `e6d9cba`: earlier handwritten-letter prototype using shared typed assessments and native workflow.
- Mono `354e0b715`: readable workflow graph colors.
- Mono `2fca37dfc`: another session committed the pending AI workflow changes alongside its own PHPDoc work before this handoff. Mono was clean when checked. Do not duplicate or undo that commit. The earlier formatter/import-convention commit blocker is no longer outstanding.
- Harvest `2c86425`: another session's checkpoint includes this session's Rector removal. `rector.php` remains; PHP-parser remains available.

## Verification completed

Both Mediary and Harvest resolve ai-workflow-bundle to the mono source through vendor symlinks.

- Mediary: `php bin/phpunit tests/Ai tests/Workflow tests/Service/MetadataOcrWorkflowTest.php tests/Service/ImagorMetadataTest.php` — 46 tests, 219 assertions.
- Harvest: `php bin/phpunit tests/Service/TranscriptSummaryWorkflowTest.php tests/Enhance/AiClaimTagsListenerTest.php tests/Workflow/NativeWorkflowTest.php` — 8 tests, 71 assertions.
- Both applications: `php bin/console lint:container` passed.
- Pipeline: `php bin/phpunit tests/Letter tests/Workflow` — 30 tests, 139 assertions; container and edited Twig lint passed.
- Mono operation cache: 2 tests, 9 assertions passed before the later cross-session commit.

These are focused compatibility checks, not full production or batch validation. Avoid casually running the whole pipeline suite: configured credentials can enable live paid tests.

## Results and limitations

Photo: both new calls received 1942 (year precision), no established location/orientation. Only the second received the NARA caption. Same original GIF bytes, 487 × 600 pixels, embedded as data URI. Cloud synchronous gpt-5.4 calls took 10.30s and 9.29s; timings include task processing and provider round trip, not an isolated inference measurement. Original pixels-only diagnostic is preserved. Human correction: vehicle says OFFICE, not COFFEE. Caption-assisted output reads OFFICE. Single samples do not establish causal improvements.

Letter: live flow completed via explicit source assessment after AI could not resolve correspondence. Transcription needs human review; extraction focuses too much on appearance; an earlier description includes an unexpected Arabic word. Do not present it as a verified catalog record. Source rule is exact-item scoped in `config/packages/letter.yaml`.

Inkstory: normal workflow uses supplied LOC ALTO OCR from blocks TB75–TB80, then extract_metadata and summarize. Neither crop nor whole page is sent to those analysis calls. The gallery crop is CSS only. ALTO coordinate space is 6162 × 8818; actual cached raster is 2465 × 3528. Crop coordinates 3119,6368,883,1833 correspond to approximately 353 × 733 raster pixels. Name readings require checking; generated Annual Meeting title is unsupported.

Tiny /meta diagnostic: a request through Mediary's configured Imagor client returned successfully and was saved to `/tmp/ink-crop-meta.json`. This is a temporary, uncommitted artifact, not a durable result. Its OCR contents have NOT yet been validated, and no downstream comparison was run. Do not claim that comparison is complete. Existing client has separate crop and meta methods: OCR reflects the source supplied to meta, so crop-specific OCR needs a real crop source. Normal LOC use should retain its existing OCR, not re-OCR the collection. This diagnostic is optional, not a launch dependency.

## Resume priorities

1. Review and improve the letter result and clipping interpretation without silently overwriting historical evidence.
2. Make source assets and exported outputs reproducible in a clean deployment; see `docs/case-study-smoke-results.md` and `public/data/*results.json` / `typewriter-comparison.json`.
3. Prepare a production, read-only client gallery. Collapsed controls are not access control: restrict execution/admin routes, disable debug UI, and inspect raw records for private URLs and paths before publishing.
4. Choose host/domain, deploy, and smoke-test all three case pages and sources.

Defer PDF taxonomy, facets/search, recipe redesign and general telemetry until this three-case showcase works reliably.

## Local setup

Actual repositories are sibling directories under `/Users/tac/sites`: ai-pipeline-demo, mono, mediary, harvest, ink. Use `../mono/link .` from an app for shared-source links; do not edit vendor copies or introduce Composer repository overrides. Pipeline credentials were obtained from Mediary with user authorization and remain in ignored local configuration; do not copy them into notes or commits. Pipeline shared media directory is local var/media. Other sessions may continue working in these repositories: always inspect current status before editing or committing.
