## Developer enablement overview

This guide walks through the lifecycle of introducing new clustering or scoring logic, verifying the behaviour with automated tests, and shipping explainability artefacts that help reviewers understand the impact of the change. Use it together with the [cluster metadata guide](./cluster-metadata.md) and the [testing fixtures catalogue](./testing-fixtures.md) for deep dives into specific datasets and reference outputs.

## Implementing a new clustering or scoring strategy

1. Locate the Symfony service or pipeline entry point under `src/`. Strategy services typically live beside their domain: clustering heuristics under `src/Cluster/Strategy`, scoring logic under `src/Scoring/Strategy`, and shared traits in `src/Cluster/Util`.
2. Add the new service class and ensure it is tagged for discovery. Update `config/services.yaml` (or the dedicated feature file) with the appropriate tag:

   ```yaml
   services:
     MagicSunday\Memories\Cluster\Strategy\SeasonalMemoryClusterer:
       tags: ['memories.clusterer']
   ```

3. Confirm the strategy is registered with the orchestrating service in `src/Memories.php` or the specific pipeline builder. If constructor autowiring fails, add explicit arguments or aliases in the service definition.
4. Extend configuration defaults where applicable. For scoring knobs, adjust `config/packages/memories.yaml`; for new feature flags, document the key and default in `docs/configuration-files.md`.
5. If the change requires database schema updates (e.g., storing new scoring features), generate a Doctrine migration:

   ```bash
   php bin/console doctrine:migrations:diff
   php bin/console doctrine:migrations:migrate --dry-run
   ```

   Review the generated migration to ensure indexes and column types align with runtime expectations before committing.

### Tagging reference

- Clusterers: `memories.clusterer`
- Scorers: `memories.scorer`
- Explainability exporters: `memories.explain`

These tags keep the pipeline discoverable through the service locator defined in `src/Service/ClusterPipelineFactory.php`.

## Writing PHPUnit and functional tests

1. Start with fixture coverage. Reuse or extend datasets under `fixtures/memories/` as described in the [testing fixtures catalogue](./testing-fixtures.md). When new behaviour requires fresh expectations, duplicate the closest dataset and update `metadata.json` plus `expected.yaml`.
2. Write unit tests in `test/Unit/...` for pure scoring heuristics, or `test/Integration/...` for end-to-end cluster pipeline checks. Follow the existing namespace conventions (`MagicSunday\Memories\Tests\...`).
3. Use the pipeline helpers from `test/Integration/Clusterer/MemoryDatasetClusterPipelineTest.php` to execute a dataset against the new strategy. Update assertions to cover:
   - cluster membership changes,
   - ranking adjustments, and
   - any new explainability annotations.
4. Run PHPUnit locally before submitting:

   ```bash
   composer test
   ```

5. For behavioural verification across the Symfony Console, add a functional test under `tests/Console/` (note the plural directory). Boot the kernel, run the command via the helper `CommandTester`, and assert on the JSON payload. Store long JSON samples under `tests/_data/` to keep the test readable.

## Generating and reviewing explainability outputs

1. Execute the clustering console command with explainability flags to preview the impact:

   ```bash
   php bin/console memories:cluster --dry-run --explain --dataset fixtures/memories/winter_weekend
   ```

   Replace `winter_weekend` with the dataset that reflects your change. The command renders the textual explanation plus the exported HTML summary.
2. Attach the generated explainability artefacts to the merge request. Model cards live in `docs/cluster-metadata-integration.md`; update the relevant section with deltas, rationale, and any new metrics.
3. Ensure reviewers can reproduce the output by documenting the exact command in the PR description and noting any prerequisites (e.g., migrations that must be run beforehand).

## Common pitfalls and verification checklist

- **Service autowiring:** If Symfony cannot discover your strategy, confirm the namespace matches the class path and that the service tag is present. For complex constructor dependencies, declare them explicitly in `config/services.yaml` to avoid circular references.
- **Migration drift:** Always run `php bin/console doctrine:migrations:migrate --dry-run` before committing to detect pending changes. Coordinate migration filenames to avoid clashes in CI.
- **Fixture parity:** Update `expected.yaml` via the fixture tooling documented in [cluster metadata integration](./cluster-metadata-integration.md) so tests assert against realistic outcomes.
- **Explainability stale artefacts:** Regenerate HTML summaries whenever datasets change; otherwise, reviewers will compare mismatched versions.

Before requesting review, confirm:

- [ ] Services compile without errors: `php bin/console debug:container memories.clusterer`
- [ ] PHPUnit suites pass: `composer test`
- [ ] Doctrine migrations are up-to-date: `php bin/console doctrine:migrations:status`
- [ ] Explainability outputs reflect the new behaviour and are referenced in the PR summary.
