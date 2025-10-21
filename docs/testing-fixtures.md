## Memory clustering fixture catalogue

This note explains how the curated memory datasets under `fixtures/memories/` are structured, how the accompanying gold-standard
files are generated, and how to extend the catalogue when new scenarios are required.

### Fixture layout

Each dataset lives in `fixtures/memories/<dataset>/` and contains three building blocks:

- `metadata.json` – compact description of clusters, members, locations, roles, and expected coverage. Timestamps use ISO 8601 with
  explicit offsets so that duration calculations remain deterministic.
- `*.svg` preview illustrations – lightweight vector placeholders (viewBox 64×64) that allow the pipeline to verify media references without
  bloating the repository. Keep the number of images per dataset minimal.
- `expected.yaml` – the canonical pipeline output (clusters, key photos, storyboard hints) that the integration test asserts
  against. The file is generated from the metadata via the helper pipeline described below and should be committed alongside the
  metadata.

### Integration test flow

`test/Integration/Clusterer/MemoryDatasetClusterPipelineTest.php` wires the loader (`MemoryDatasetLoader`) and pipeline
(`MemoryDatasetPipeline`) together. For each dataset it performs the following steps:

1. Parse metadata and validate that all referenced previews exist.
2. Execute the pipeline, which aggregates members, selects key photos, calculates coverage, and synthesises storyboard sequences.
3. Compare the result with `expected.yaml` to guarantee that the curated scenario remains stable.

The test suite runs as part of `composer ci:test:php:unit`.

### Regenerating the gold standard

Whenever metadata changes, regenerate `expected.yaml` using the built-in helper:

```bash
php <<'PHP'
<?php
require __DIR__ . '/vendor/autoload.php';

use MagicSunday\Memories\Test\Support\Memories\MemoryDatasetLoader;
use MagicSunday\Memories\Test\Support\Memories\MemoryDatasetPipeline;
use Symfony\Component\Yaml\Yaml;

$baseDir = __DIR__ . '/fixtures/memories';
$loader = new MemoryDatasetLoader($baseDir);
$pipeline = new MemoryDatasetPipeline();

foreach ($loader->availableDatasets() as $dataset) {
    $result = $pipeline->run($loader->load($dataset));
    file_put_contents($baseDir . '/' . $dataset . '/expected.yaml', Yaml::dump($result, 6, 2));
}
PHP
```

The script iterates over all available datasets, replays the pipeline, and rewrites their gold-standard files.

### Feed storyboard snapshot

`test/Integration/Http/FeedStoryboardIntegrationTest.php` exercises the HTTP feed controller with the curated
`familienevent` dataset. The test instantiates real feed helpers (text generator, notification planner, storyboard
transitions) and asserts that the storyboard payload rendered for each feed item matches the JSON snapshot stored under
`test/Integration/Http/__snapshots__/feed_storyboard.json`. When metadata or slideshow settings change intentionally,
re-run the test, inspect the reported diff, and update the snapshot to keep it in sync.

### Adding a new scenario

1. Create a new directory under `fixtures/memories/` and add a handful of preview images (follow the ≤64×64 px guideline).
2. Author `metadata.json` with cluster ids, titles, summaries, `expected_days` lists, and per-item roles (`key`, `highlight`) so the
   pipeline can derive gaps, coverage, and storyboard beats.
3. Run the regeneration script above to produce the initial `expected.yaml`.
4. Execute `composer ci:test:php:unit` to confirm that the integration test passes.
5. Document noteworthy behaviour (e.g., new gap logic) in the corresponding PR or decision log entry.

### Maintenance tips

- Keep datasets lightweight: favour short timelines and a limited number of members to reduce test execution time.
- When restructuring metadata fields, update both the loader and pipeline assertions so invalid fixtures fail fast with descriptive
  error messages.
- Treat `expected.yaml` as the authoritative record of the curated scenario. Changes should be deliberate and reviewed together
  with the altered metadata.
