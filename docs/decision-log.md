# Decision Log

## 2025-10-14 – Guard peripheral-only vacations and expand telemetry
- **Author:** ChatGPT (gpt-5-codex)
- **Context:** Vacation clustering occasionally produced day-trip drafts composed solely of peripheral days, which created low-quality stories and lacked observability around duplicate filtering and spacing relaxations.
- **Decision:** Short-circuit vacation draft creation when no core days remain (unless the configured weekend exception applies) and enrich run metrics with dedupe rate, average spacing, and applied relaxations so monitoring captures the selection posture.
- **Alternatives considered:** Keep emitting drafts and only rely on scoring penalties, or log the metrics without surfacing them through the monitoring emitter—both rejected because they hid the failure reason and required manual log inspection.
- **Follow-up actions:** Observe production telemetry for elevated `missing_core_days` counts and adjust selection profiles or relaxations if legitimate trips are being filtered out.

## 2025-10-13 – Adaptive vacation selection thresholds
- **Author:** ChatGPT (gpt-5-codex)
- **Context:** Vacation member selection needed to surface adaptive limits so telemetry and downstream policies can react to day classifications and near-duplicate density.
- **Decision:** Compute per-day quotas from run length and day context, derive staypoint and pHash thresholds dynamically, and expose the resulting caps and relaxations through telemetry for observability.
- **Alternatives considered:** Keep static per-day/per-staypoint quotas and the configured pHash minimum, which was rejected because it could not respond to mixed core/peripheral runs and dense duplicate bursts.
- **Follow-up actions:** Monitor production telemetry for threshold regressions and adjust percentile sampling or cap heuristics if real runs show skewed distributions.
