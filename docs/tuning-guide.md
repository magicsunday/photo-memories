## Purpose
This guide consolidates the primary knobs for storyline scoring, clustering continuity, and slideshow pacing so operators can adjust deployments confidently while keeping configuration defaults close at hand.

## Global Scoring Weights
- **Defaults:** Quality 0.22, Relevance 0.45, Liveliness 0.08, Diversity 0.25 (`MEMORIES_SCORING_WEIGHTS_*`).【F:config/packages/memories.yaml†L25-L36】
- **Recommended adjustment windows:** Quality 0.2–0.3, Relevance 0.4–0.6, Liveliness 0.05–0.15, Diversity 0.2–0.3 to match feed tone and motion appetite.【F:README.md†L69-L75】
- **Troubleshooting:** If scores skew toward static albums, tilt Liveliness upward and verify the resulting member mix via `memories:curate --dry-run --explain` model cards before locking in changes.【F:README.md†L126-L129】【F:docs/runbook-feed-preview-cli.md†L33-L41】

## Clustering Thresholds
- **Time gap (`MEMORIES_THRESHOLDS_TIME_GAP_HOURS`):** Default 2.0 h; experiment between 1–12 h to control storyline fragmentation. Lower values stitch dense day stories; higher values preserve multi-day travel arcs.【F:config/packages/memories.yaml†L5-L13】【F:README.md†L69-L70】【F:README.md†L126-L128】
- **Space gap (`MEMORIES_THRESHOLDS_SPACE_GAP_METERS`):** Default 250 m; tune within 150–500 m to balance local walks against destination jumps.【F:config/packages/memories.yaml†L10-L13】【F:README.md†L69-L70】【F:README.md†L126-L128】
- **Vacation minimum days (`MEMORIES_VACATION_MIN_DAYS`):** Inherits three-day baseline from the vacation cluster profile, with operational room between 2–7 days depending on weekend policies and travel cadence.【F:config/packages/memories.yaml†L15-L18】【F:config/parameters.yaml†L143-L145】【F:README.md†L71-L72】
- **Duplicate stacking (`MEMORIES_DUPSTACK_HAMMING_MAX`):** Default 9; nudge to 6–12 to tighten or loosen perceptual dedupe bins. Watch for rising false positives when dipping below 8 and re-run curate dry-runs to confirm stack sizes.【F:config/packages/memories.yaml†L20-L23】【F:README.md†L71-L72】【F:docs/runbook-feed-preview-cli.md†L25-L32】
- **Diagnostic tips:** When adjusting any threshold, capture `memories:curate --dry-run --explain` output to inspect drop reasons and leverage feed previews to ensure cluster continuity matches expectations.【F:README.md†L126-L129】【F:docs/runbook-feed-preview-cli.md†L33-L41】

## Slideshow Parameters
- **Defaults:** Duration per image 3.5 s, transition duration 0.75 s, Ken Burns zoom min/max 1.03/1.08 (`MEMORIES_SLIDESHOW_*`).【F:config/packages/memories.yaml†L38-L49】【F:config/parameters.yaml†L916-L923】【F:config/parameters.yaml†L996-L1002】
- **Recommended adjustment windows:** Pace slides between 3–6 s, transitions 0.5–1.5 s, zoom min around 1.0–1.1, zoom max 1.05–1.2 to match soundtrack tempo and output channel.【F:README.md†L74-L75】
- **Troubleshooting:** If renders feel jittery, align duration + transition totals with the beat grid and confirm zoom spans stay within the declared min/max pair before re-exporting previews.【F:README.md†L74-L75】【F:docs/configuration-files.md†L56-L64】

## Validation Checklist
- [ ] Run `php src/Memories.php memories:curate --dry-run --types=… --explain` to inspect selection telemetry and model cards before persisting changes.【F:README.md†L126-L129】【F:docs/runbook-feed-preview-cli.md†L33-L41】
- [ ] Review explainability reports (`var/log/memories/model-cards`) for scoring deltas and rejection reasons tied to the new tuning.【F:docs/runbook-feed-preview-cli.md†L33-L41】
- [ ] Execute `php src/Memories.php memories:feed:preview --limit-clusters=2000` to validate feed ordering under the updated configuration.【F:docs/runbook-feed-preview-cli.md†L10-L23】【F:README.md†L97-L100】
