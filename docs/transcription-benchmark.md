# Transcription benchmark pilot

The commands use this application's configured Symfony AI platforms. No separate OCR implementation or Python runner is involved.

```sh
php bin/console app:benchmark:import \
  https://fromthepage.com/aucklandmuseum/transcription-projects/rnzaf-biographies-of-deceased-personnel-ea-gw-r17845610/display/32783703 \
  var/benchmarks/rnzaf --count=6

php bin/console app:benchmark:run var/benchmarks/rnzaf/manifest.json \
  mistral mistral-ocr-latest var/benchmarks/runs/mistral --ocr

php bin/console app:benchmark:run var/benchmarks/rnzaf/manifest.json \
  openai gpt-5.4 var/benchmarks/runs/gpt-5.4

php bin/console app:benchmark:run var/benchmarks/rnzaf/manifest.json \
  openresponses.ai_tools florence-2 var/benchmarks/runs/florence --pages=32783706
```

Use a new directory for each snapshot and run. `--pages` selects source page IDs, not ordinal numbers. A Mistral OCR run uses `ImageUrl` with the frozen image's data URL; a vision run uses `MessageBag` with an image and the recorded transcription prompt. References are read for scoring only and never sent to a provider.

The importer intentionally supports the verified RNZAF CC BY 2.0 work-level permission statement, public completed pages, and the current direct-image viewer format. It is a pilot importer, not a general FromThePage API client. It fails on unrecognized rights/status/image formats. A failed import may leave a partial directory; inspect it and choose a new output directory for a retry.

The snapshot includes images, reference text, raw page HTML, the rights page, SHA-256 hashes and source modification dates. Modification dates are not treated as human revision IDs. Preserve source snapshots to retain attribution/provenance; a Complete flag is not independent verification that every edit was human or correct.

## Initial sample, 20 October 2026

Six consecutive RNZAF Ea–Gw pages (R17845610), 1305 × 2000 pixels each. Five contain some handwriting; page 4 (32783706) has substantial corrections and handwritten hours. Page 6 (32783708) is a predominantly typed control. This is not a representative handwriting corpus.

Source: Royal New Zealand Air Force; Te Rua Mahara o te Kāwanatanga / Archives New Zealand; Auckland War Memorial Museum Tāmaki Paenga Hira; FromThePage contributors. Source permission: https://creativecommons.org/licenses/by/2.0/. Work: https://fromthepage.com/aucklandmuseum/transcription-projects/rnzaf-biographies-of-deceased-personnel-ea-gw-r17845610/about

The first live sample and runs are saved under:

`/Users/tac/Documents/Codex/2026-10-20/https-fromthepage-com-findaproject-https-fromthepage/outputs/rnzaf-benchmark`

| Model | Pages | Character error rate | Word error rate |
| --- | ---: | ---: | ---: |
| mistral-ocr-latest | 6 | 1.76% | 3.82% |
| gpt-5.4-2026-03-05 | 6 | 2.64% | 4.91% |

These are agreement scores against unadjudicated references, not accuracy rankings. Page 3's completed reference omits a visible phrase about the flying badge and promotion. Page 4 includes crossed-out text and has `19323` where the image reads `1923`. Prompt/editorial conventions, Markdown, reading order and reference errors affect scores.

CER and WER use Unicode NFC and collapsed whitespace, retaining case/punctuation/Markdown. Aggregates divide total edit counts by total reference lengths; rates can exceed 100%, and empty references have null rates. Raw outputs, model names returned by providers, latency and usage are retained. Prices are deliberately null until a dated pricing table is supplied. `latest` is mutable; Mistral returned that alias rather than a pinned model version.

OpenAI ran with the authorized key from Mediary supplied only in the process environment. The configured self-hosted AI-tools service currently requires Bearer authentication; Florence did not produce a scored result. An OpenResponses error-parser bug initially masked that 401; the isolated fix is https://github.com/symfony/ai/pull/2711.

Next: independently adjudicate a versioned reference set, capture project-specific editorial conventions and contributor revision history, add handwriting-region scoring, configure AI-tools authentication, and benchmark pinned model versions. Keep the original references unchanged. These source/reference/run distinctions should carry into the future document-centric site.

## Aligned institutional handwriting pilot — 24 October 2026

Three Ea–Gw institutional scans were visually matched to the frozen human transcripts before new inference runs. `resources/benchmarks/rnzaf-handwriting-v1.json` records explicit file IDs, sequence checks, visual anchors and pixel crop coordinates. No corpus-wide page offset is assumed. The selected pairs are FL64924527 ↔ 32783703, FL64924856 ↔ 32783704, and FL64924653 ↔ 32783706.

`app:benchmark:align` checks source-image, source-HTML and reference hashes, verifies each excerpt is verbatim, and writes lossless PNG crops without resizing. It retains the original human references and HTML, institutional inventory and rights evidence. Crop boundaries were visually checked and corrected before the successful inference runs. Earlier draft snapshots and catalog-resolution failures are not scored runs.

The frozen input manifest is:

`/Users/tac/sites/ai-pipeline-demo/var/benchmarks/rnzaf-handwriting-v1-frozen/manifest.json`

SHA-256: `a057dd5b9088f8fc6f7311f3df961b2b450d6141db0388604c7bd84465f76f65`.

It contains three full-page crops, three handwriting-focused regions and one separate cancelled-number diagnostic. The handwriting-focused regions contain only **100 reference characters / 15 whitespace-delimited words**, including typed context. This is an alignment and interoperability pilot, not a representative cursive-handwriting benchmark. Review was performed by AI against the source images, not by an independent human adjudicator. Human completion status does not establish independent transcript licensing; preserve the existing rights caveat.

```sh
# Reproduce crops into a NEW output directory:
php bin/console app:benchmark:align resources/benchmarks/rnzaf-handwriting-v1.json \
  /path/to/original/human-reference/manifest.json \
  /Users/tac/data/vault/mus/_capture/rnzaf-R17845610 /path/to/new/snapshot

# Successful runs used these exact input bytes and explicit model versions:
php bin/console app:benchmark:run var/benchmarks/rnzaf-handwriting-v1-frozen/manifest.json \
  mistral mistral-ocr-4-1 /path/to/new/mistral-run --ocr --model-template=mistral-ocr-latest
php bin/console app:benchmark:run var/benchmarks/rnzaf-handwriting-v1-frozen/manifest.json \
  openai gpt-5.4-2026-03-05 /path/to/new/gpt-run --model-template=gpt-5.4

php bin/console app:benchmark:compare /path/to/mistral-run/report.json \
  /path/to/gpt-run/report.json /path/to/new/comparison.json
```

`--model-template` takes only the known catalog model's adapter class, capabilities and default options; the explicit requested version is sent to the provider unchanged. Both providers returned the requested version IDs. This avoids modifying vendor catalogs when they lack recently available or dated identifiers. Mistral's account model list confirmed `mistral-ocr-4-1` availability. Mistral uses its native OCR input; GPT uses the recorded transcription prompt. They receive identical image files but different provider interfaces. No reference text is sent to either model. Keys are supplied through process environment only; none are saved in the benchmark.

| Evaluation group | Mistral OCR 4.1 CER / WER | GPT-5.4 dated version CER / WER |
| --- | ---: | ---: |
| Three full pages (4,859 characters / 827 words) | 1.77% / 3.51% | 1.15% / 2.54% |
| Three handwriting-focused regions (100 characters / 15 words) | 4.00% / 40.00% | 12.00% / 46.67% |
| Cancelled-number diagnostic (20 characters / 4 words) | 45.00% / 50.00% | 10.00% / 50.00% |

These are agreement scores against unchanged human references. Region WER is especially sensitive to spacing in abbreviations and hyphens: both models read the pencil heading and A.S.2 annotation, but `EA - GW.` differs from the reference's `EA-GW.`. GPT places `(W)` after the address while the reference puts it on the first line. On the diagnostic, Mistral retains the struck-out number using Markdown, while the reference omits it. The full Ealam reference still contains the known `19323` typo and crossed-out passages. These editorial effects preclude a handwriting-accuracy ranking. Groups overlap by source page and must not be pooled as independent samples.

Successful raw outputs, usage, timing and per-input scores are in `var/benchmarks/runs/rnzaf-v1-frozen-mistral` and `var/benchmarks/runs/rnzaf-v1-frozen-gpt`. The comparison is `var/benchmarks/rnzaf-handwriting-v1-comparison.json`. Pricing remains unset. `app:benchmark:compare` rejects mismatched manifests, selections, hashes, groups and incomplete runs.

For local models, pass the same frozen manifest to `app:benchmark:run` with the configured local platform and vision model. Do not regenerate crops or change references between runs. AI-tools authentication remains a separate configuration prerequisite; no local model run is claimed here. The existing 1,339 downloaded institutional scans are sufficient for expanding this pilot after human review and alignment; additional bulk acquisition is not the present bottleneck.
