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
