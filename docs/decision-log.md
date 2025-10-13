# Decision Log

## 2025-10-13 – Adaptive vacation selection thresholds
- **Author:** ChatGPT (gpt-5-codex)
- **Context:** Vacation member selection needed to surface adaptive limits so telemetry and downstream policies can react to day classifications and near-duplicate density.
- **Decision:** Compute per-day quotas from run length and day context, derive staypoint and pHash thresholds dynamically, and expose the resulting caps and relaxations through telemetry for observability.
- **Alternatives considered:** Keep static per-day/per-staypoint quotas and the configured pHash minimum, which was rejected because it could not respond to mixed core/peripheral runs and dense duplicate bursts.
- **Follow-up actions:** Monitor production telemetry for threshold regressions and adjust percentile sampling or cap heuristics if real runs show skewed distributions.
