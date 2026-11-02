# Local folder batches

The first inventory intake is a local folder, read through Flysystem. A camera/scanner app can
remain a separate producer. The local hackathon demo does not need S3, a mobile client,
Mediary, ZIP extraction, or an upload service to exercise image observation and text analysis.

## Fresh local installation

Use PHP 8.5 with SQLite, fileinfo and mbstring available, and install the project with Composer:

```bash
composer install
```

For a **new, empty local database**, set this in `.env.local` alongside the AI provider
credentials required by `config/packages/ai.yaml`:

```dotenv
DATABASE_URL="sqlite:///%kernel.project_dir%/var/inventory.db"
```

Create the fresh SQLite schema from the existing Doctrine mappings, then start the local server:

```bash
php bin/console doctrine:schema:create
symfony server:start
```

The historical migration is PostgreSQL-specific. The schema-create command above is only for
an empty disposable SQLite installation; do not run it against an existing application database.
Existing configured installations need no schema change for this feature. The Symfony CLI is
convenient for serving the app; the application itself is installed through Composer.

## Import, process, review

Arrange the images, for example:

```text
loan-closet/
  wheelchair-01/
    label.jpg
    whole-item.jpg
  crutches-02/
    pair.jpg
  shower-seat.jpg
```

```bash
php bin/console app:batch:import /absolute/path/to/loan-closet --collection="Medical loan closet" --dry-run
php bin/console app:batch:import /absolute/path/to/loan-closet --collection="Medical loan closet"
```

The command prints a batch ID and `/batches/ID` review path. Import creates prepared gallery
subjects with pending observation tasks, deliberately bypassing the initial-place automatic
workflow kickoff. No AI runs during import or dry-run. Dry-run validates files and checks
existing records without writing files or rows; it still needs the configured database.

When ready, use the printed ID:

```bash
php bin/console app:batch:process BATCH_ID
```

This runs the existing workflow synchronously: observation, selected image follow-ups, and
text analysis. It uses configured AI providers and may incur their usual charges. It is a
batch of local subjects, not a provider's discounted asynchronous Batch API. No Messenger
worker is required for this command. Completed tasks are removed from the existing pending
queues; rerunning resumes eligible pending work and skips finished subjects. A provider error
stops the command with an error; fix it and rerun. Do not run competing processors for one batch.
Workflow states and task evidence are visible on the existing gallery detail page. The workflow's
review/publish states are pipeline stages, not a new human approval or inventory-save feature.

Visit `/batches`, choose the collection batch, and open an image to inspect its recorded claims
and runs. Refresh to update progress. Collection names such as Bar, Pantry, or Tool shed use the
same intake; they are context supplied to observation, not separate plugins.

## Evidence and boundaries

- JPEG, PNG, WebP and GIF files are validated by contents. Other extensions are ignored;
  corrupt image files fail visibly. Limits: 20 MiB per image, 1,000 images per import.
- Flysystem refuses symlinks encountered within the mounted directory. The CLI operator chooses
  the source directory; no HTTP endpoint accepts arbitrary filesystem paths.
- Originals are copied to `var/batch-images`, outside `public`. Image responses resolve only
  imported records inside that cache. AI tasks receive data URIs made from cached bytes;
  they need no publicly reachable local URL, Imagor or imgproxy for this input path.
- This remains a local, single-user demo with the repository's existing access model. Bind the
  server locally. Private filesystem storage does not provide HTTP authentication; a hosted
  multi-user app needs authentication and collection authorization before deployment.
- Identity includes the absolute source directory, collection, relative filename and SHA-256
  contents. Reimporting unchanged files preserves claims and queues. Changed files create new
  image records rather than silently replacing evidence; removed source files stay in the batch.
  Moving the directory or changing collection creates a separate batch.
- Each accepted file is committed independently. A failed import can leave a partial batch;
  fix the invalid file and repeat the import. Dry-run is useful before a large intake.
- Subfolder paths preserve grouping. A loose image is its own group. **Each photo currently runs
  independently**: label plus whole-item photos are not yet synthesized into one inventory item.
- Batch provenance uses the existing JSON column; listing batches scans gallery records. This
  is deliberately a small local demo, not a scalable import index.

Next: combine a group's observations into a suggested inventory record, edit/save it to a
collection, and add collection chat. ZIP/multiple-file upload and a scanner ingestion API can
feed the same intake later. Imagor/UX Image can supply browser renditions independently of
import; this change preserves the existing remote-image imgproxy path and does not add that bridge.

## Verification

```bash
php bin/phpunit tests/Batch tests/Workflow
php bin/console lint:container
php bin/console lint:twig templates/batch templates/gallery.html.twig templates/gallery/show.html.twig
```

These targeted tests use fake AI tasks and isolated SQLite databases. They cover no-dispatch
intake, grouping, deduplication, changed-file evidence, unsupported contents, symlink refusal,
local image bytes reaching vision, text-only analysis, repeat processing, and rendered review routes.
