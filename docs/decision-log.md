# Decision Log

## 2025-10-17 – Stabilise slideshow transitions
- **Author:** ChatGPT (gpt-5-codex)
- **Context:** Slideshow overlaps still drew pseudo-random durations per render and transition fallback sequences treated all `xfade` options equally, making fades appear as often as novelty effects like `pixelize`.
- **Decision:** Clamp every overlap to the resolved global `transitionDuration` (unless storyboard overrides apply) and bound it against the available slide overlap, eliminating random draws. Rework the deterministic transition chooser to weight cinematic fades higher than experimental wipes/pixel effects while seeding the randomizer with media and cluster identifiers for stable playback.【F:src/Service/Slideshow/SlideshowVideoGenerator.php†L92-L116】【F:src/Service/Slideshow/SlideshowVideoGenerator.php†L297-L333】【F:src/Service/Slideshow/SlideshowVideoGenerator.php†L708-L765】【F:src/Service/Slideshow/SlideshowVideoGenerator.php†L820-L895】
- **Alternatives considered:** Keep per-overlap random draws and rely on manual overrides, or shuffle transitions uniformly. Rejected because they broke predictability, produced hard cuts when the overlap draw exceeded slide content, and overused gimmick transitions in family recap videos.
- **Follow-up actions:** Observe telemetry for slide overlap saturation to ensure the clamp is sufficient and expand the weighting table if additional transitions are curated.

## 2025-10-16 – Slideshow fade configuration
- **Author:** ChatGPT (gpt-5-codex)
- **Context:** The slideshow renderer lacked dedicated controls for video fade-ins/-outs, leading to abrupt cuts at the start and
  end of exported clips and inconsistent overlays when multiple slides were blended.
- **Decision:** Added configurable intro/outro fade durations to the FFmpeg filter graph, applied them to both single-image
  slideshows and multi-image timelines, and exposed the values via `memories.slideshow.intro_fade_duration_s` and
  `memories.slideshow.outro_fade_duration_s`.
- **Alternatives considered:** Keep the previous hard cuts and rely on audio fades only, or hard-code fade durations directly in
  the filter expression. Rejected because they either preserved the harsh visual transitions or required code edits for every
  adjustment.
- **Follow-up actions:** Monitor generated clips for edge cases where configured fade durations exceed the clip length and refine
  clamping logic if artefacts appear.

## 2025-10-15 – Guard duplicate parameter definitions
- **Author:** ChatGPT (gpt-5-codex)
- **Context:** Parameter collisions across `config/parameters*.yaml` were silently overwriting values during container builds, making it hard to spot misconfigured deployments and conflicting defaults.
- **Decision:** Introduced a compiler pass that inspects all imported parameter files, warns about duplicate keys (within a file and across files), and wired it into the dependency container factory so the guard runs on every boot. Added regression tests and documentation to explain the warning.
- **Alternatives considered:** Rely solely on Symfony's YAML parser (throws on same-file duplicates but stays silent across files) or parse the files before loading them. Rejected because the former misses cross-file issues and the latter duplicates loader behaviour without integration into the container lifecycle.
- **Follow-up actions:** Monitor logs for recurring warnings and consolidate parameter definitions or restructure imports if projects trigger the guard in production.

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
