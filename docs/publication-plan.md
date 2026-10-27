# Museado AI showcase: publication plan

Audience: collection owners and Museado clients. Purpose: discuss useful AI options through three real sources. Symfony implementation details remain available on demand. Keep the launch scope to a photograph, handwritten letter and newspaper clipping; add PDF and other media categories later.

## 1. Finish the three results

- NARA photo: retain the date-only/caption comparison and original diagnostic, label the OFFICE correction, verify source attribution and image dimensions. Do not imply a single comparison measures accuracy or speed improvements.
- Handwritten letter: review transcription against the image, identify uncertain passages, and make extraction use the transcription rather than mostly describing appearance. Preserve machine output and reviewed corrections distinctly. Remove or explain stray foreign-language output.
- Inkstory: keep supplied LOC OCR as the normal input. Check names and remove the unsupported Annual Meeting interpretation from any editorial summary without altering original output. Finish the optional Tiny /meta comparison only if it helps explain a client option; it is not a launch dependency. Confirm that /meta returned OCR, not only image metadata. Correct ALTO coordinates versus physical raster dimensions.

Acceptance: each case has a working source, an understandable goal, readable results, explicit limitations, and trustworthy input/model/time records. Missing price is labeled unavailable, never zero. Do not present unverified output as an approved catalog record.

## 2. Package a reproducible showcase

Export reviewed case data and retained outputs with a documented import command. Bundle or reliably serve permitted source images so the public site does not depend on local paths or fragile upstream requests. Check image/source rights and attribution for the selected items. Verify all three detail pages and the gallery in a clean environment and narrow viewport. Keep the playground data available for development but out of the public gallery.

## 3. Prepare the public deployment

Choose the publication hostname and hosting app. Deploy in production mode, with debug toolbar off. Make public pages read-only: restrict task execution, workflow transitions, admin routes and paid API operations to authenticated operators. Audit expandable/raw records for credentials, private URLs and local filesystem paths. A collapsed section is not access control. Check HTTPS, database migrations, source assets and result imports on staging before promotion.

## 4. Validate and release

Run focused pipeline checks, then Mediary and Harvest compatibility checks against the actual modified bundle source. Resolve failures without starting the deferred architecture refactor. Review and commit app and bundle changes separately, respecting their hooks and repository instructions. Smoke-test gallery, all three cases, source links and expandable results on the deployed site. Publish only after the result-quality and production checks pass.

## Deferred

PDF/audio/media taxonomy; search and facets; new recipe architecture; decision-tree redesign; general-purpose telemetry; batch comparisons; broad OCR reruns. These are not prerequisites for the three-case client showcase.
