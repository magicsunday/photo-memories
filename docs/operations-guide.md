# Operations Guide

## Quick-start CLI recipes
- **Index new media**
  ```bash
  php src/Memories.php memories:index "$MEMORIES_MEDIA_DIR" --thumbnails
  ```
  Indexes the configured media library (default `MEMORIES_MEDIA_DIR`), persists fresh metadata, and generates thumbnails. Add `--force` to reprocess unchanged files, or `--dry-run` to verify discovery without writes.
- **Refresh geocoding signals**
  ```bash
  php src/Memories.php memories:geocode --refresh-locations --missing-pois
  ```
  Rebuilds place assignments for indexed media and fills in missing POI data. Combine with `--city="Paris"` to scope the run and `--dry-run` to inspect planned updates safely.
- **Recluster curated stories**
  ```bash
  php src/Memories.php memories:cluster --dry-run --limit=2000 --since=2024-01-01
  ```
  Re-evaluates clusters without persisting changes, limiting input to the most recent media. Drop `--dry-run` and add `--replace` to overwrite existing clusters after review.
- **End-to-end curation with explainability**
  ```bash
  php src/Memories.php memories:curate --dry-run --explain --reindex=auto --types=journey
  ```
  Chains indexing, clustering, and feed export in simulation mode while writing HTML model cards for consolidated clusters. Inspect the dry-run output, then rerun without `--dry-run` (keeping `--explain` if you want explainability artefacts persisted).
- **Export an HTML preview**
  ```bash
  php src/Memories.php memories:feed:export-html var/preview --stage=curated --max-items=48
  ```
  Produces a static preview at `var/preview/index.html`, copying (or `--symlink`-ing) thumbnails and enforcing feed-stage limits. Serve the folder locally via `php -S localhost:8080 -t var/preview` when validating with stakeholders.
- **Discover automation helpers**
  ```bash
  make help
  ```
  Lists project-aware shortcuts. Targets mirror the Symfony console entrypoint (`php src/Memories.php …`) and ensure dependencies are bootstrapped before running.

## Common failure scenarios
- **Missing environment variables**
  - Symptoms: commands exit with `EnvNotFoundException` or complain about `MEMORIES_MEDIA_DIR`, `DATABASE_URL`, or API keys.
  - Fix: load `.env`/`.env.local`, confirm `direnv allow` succeeded, and export required secrets (see `config/parameters.yaml` defaults). Re-run with `printenv | grep MEMORIES_` to validate.
- **Database connectivity issues**
  - Symptoms: Doctrine connection errors (`SQLSTATE[HY000]`, `Connection refused`).
  - Fix: ensure the database container/service is running, verify `DATABASE_URL` credentials, and run `php bin/console doctrine:migrations:migrate` if schema drift is suspected. Use `MEMORIES_DB_SSL_MODE=prefer` when TLS configuration differs between environments.
- **Filesystem permission problems**
  - Symptoms: warnings about unwritable paths (`var/cache`, `var/log/memories`, export directories) or missing thumbnails.
  - Fix: grant the runtime user write access (`chown -R $(whoami): var/`), point explain/model-card directories to persistent storage via `MEMORIES_MODEL_CARD_DIR`, and re-run the command with `--dry-run` first to confirm discovery works.

## Log review and telemetry
- **Pipeline logs**: `var/log/memories/*.log` capture structured messages from indexing, clustering, and export stages. List available files with `ls -1 var/log/memories/*.log` and tail active runs via `tail -f var/log/memories/<command>.log`. Increase console verbosity with `-vvv` when chasing transient issues.
- **JSONL monitoring stream**: the monitoring channel defaults to `var/log/memories/pipeline.jsonl` (`memories.monitoring.log_path_default`). Filter noisy runs with `jq 'select(.level >= 200)' var/log/memories/pipeline.jsonl`; explainability payloads arrive as `context.stage == "explain"` entries, which you can diff between releases.
- **Explainability artefacts**: `memories:curate --explain` (and derived automation) writes HTML model cards into `%memories.explain.model_card_dir%` (default `var/log/memories/model-cards`). Each card links rejection reasons, scoring inputs, and selection telemetry.
- **Telemetry dashboards**: cluster and selection telemetry surfaces via `ClusterJobTelemetry` sections in CLI output and optional exporters. Forward JSONL entries into your observability stack (e.g., `fluent-bit` → Loki) to correlate runtimes, rejection counts, and feed caps over time.
- **Troubleshooting tips**: correlate CLI timestamps with log entries; for unexpected gaps, enable Symfony debug verbosity (`-vvv`) and inspect `var/log/dev.log`. When running explain dry-runs, copy JSON fragments from the model-card HTML or `pipeline.jsonl` to compare policies between releases.

## Post-merge checklist
1. Pull the latest `main`, install dependencies (`composer install`, `npm install` if the web preview is touched).
2. Run a dry-run of the full pipeline on the example corpus:
   ```bash
   php src/Memories.php memories:curate --dry-run --explain --reindex=auto --types=journey
   ```
   Confirm explain model cards land in `var/log/memories/model-cards` and review the CLI QA report.
3. Execute the full curation with writes enabled:
   ```bash
   php src/Memories.php memories:curate --reindex=auto --explain
   ```
   Ensure clustering completes, feed export succeeds, and telemetry shows healthy rejection ratios.
4. Publish an updated HTML preview for stakeholders:
   ```bash
   php src/Memories.php memories:feed:export-html var/preview --stage=curated --max-items=48
   ```
   Verify `var/preview/index.html` renders locally and bundles the expected thumbnails.
5. Archive logs (`var/log/memories/`), JSONL telemetry, and explain artefacts alongside the release notes. Attach anomalies or follow-up tasks to the decision log.
