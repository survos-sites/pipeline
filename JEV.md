# Jev demo

The demo uses published Symfony AI 0.14 releases installed through Composer:
AI platform, bundle, and agent are locked to `v0.14.1`; the TypeSafe bridge and
AI store are locked to `v0.14.0`. The requirements use `^0.14`, without development
branches or version aliases. TypeSafe support is included in these tagged releases.

Run `composer install` to install the versions recorded in `composer.lock`.

Set `TYPESAFE_API_KEY` in the ignored `.env.local` file or export it in your shell.
Then run:

```sh
php bin/jev-demo.php
php bin/jev-demo.php 'A handwritten letter dated June 3, 1880.'
```

This sends the supplied text to TypeSafe and prints a classification, confidence,
date probability, and detail score. The default input is a fictional museum record.
It runs without booting the application kernel or accessing a database.

The app also configures the `ai.platform.typesafe` service for future integration.
Jev evaluates typed questions; it is not a chat agent model.

Official Symfony example:
https://github.com/symfony/ai/blob/main/examples/typesafe/evaluate.php

## Blog tagging proof

`php bin/jev-blog-demo.php [article-slug]` reuses Symfony's `RssFeedLoader` and the
same FeedBurner source as the Symfony AI demo. It evaluates one complete article,
without splitting or embedding it. The default is the role hierarchy article.
The fixed list contains the website's 11 category names plus six technical topics.
Source categories are scraped from the article's "Published in" section and are
held out from model input. A provisional 0.8 threshold selects tags; all raw
probabilities, questions, source metadata, model and timing are kept in the JSON.

Save the JSON to a file, then from the Symfony AI demo run:

```sh
symfony console app:blog:import-tags /absolute/path/to/result.json
```

The importer upserts by canonical URL into `symfony_blog_article` in the existing
Postgres database. It persists full article text, original categories and Jev
results. The existing `symfony_blog` table stores vector chunks with a required
embedding, so article records are separate. Importing tags does not generate
vectors or alter the existing vector rows. An optional vectorization step is a
future extension; this proof does not implement a `--vectorize` switch or a UI.
