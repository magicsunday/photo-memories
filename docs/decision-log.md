# Decision Log

## 2025-10-27 – Boost iconic POI clusters via pHash dominance
- **Author:** ChatGPT (gpt-5-codex)
- **Context:** POI scoring treated every labelled cluster equally, even when member photos clearly focused on a single community-recognised motif (e.g. Brandenburger Tor). Without a dominance signal, telemetry could not explain why iconic scenes failed to surface, and manual adjustments to category boosts distorted other locations.
- **Decision:** Extended `PoiClusterScoreHeuristic` to analyse member pHashes, persist dominance telemetry (`poi_iconic_*` params), and add a configurable `%memories.score.poi.iconic_boost%` when the dominant hash either matches `%memories.score.poi.iconic_signatures%` or clears `%memories.score.poi.iconic_similarity_threshold%`. Updated configuration docs and unit tests to cover the new scoring path.
- **Alternatives considered:** Maintain static category boosts (too coarse for motif-specific highlights) or add a standalone curator stage (duplicated scoring logic and delayed telemetry). Both were rejected because they obscured why iconic clusters underperformed and complicated tuning.
- **Follow-up actions:** Monitor cluster telemetry for `poi_iconic_*` ratios to confirm the boost triggers on intended motifs only, and expand the signature catalogue once community feedback identifies additional landmarks.

## 2025-10-26 – Raise vacation minimum away days to three
- **Author:** ChatGPT (gpt-5-codex)
- **Context:** Two-day getaways frequently slipped through the curation guardrails once the weekend exception toggled in, even when operators expected the default pipeline to focus on longer vacations. As a result, short transit hops flooded review queues and diluted highlight quality.
- **Decision:** Increased `memories.cluster.vacation.min_away_days_default` to three, mirrored the new default in `.env.dist` and documentation, and extended the score-calculator tests to assert that two-day runs are rejected unless the configuration explicitly relaxes the threshold.
- **Alternatives considered:** Disable the weekend exception globally (too coarse and removed a desired feature) or only tighten selection profiles (left scoring logic accepting short runs). Both options were rejected because they either harmed legitimate weekend trips or failed to enforce the baseline minimum days requirement.
- **Follow-up actions:** Monitor telemetry for `cluster.vacation` runs to confirm that legitimate long-weekend trips still succeed when the explicit weekend exception applies, and revisit the minimum once new regional profiles are tuned.

## 2025-10-25 – Export typed media index metadata files
- **Author:** ChatGPT (gpt-5-codex)
- **Context:** Downstream search/index builds required a stable, machine-readable view of all ingestion signals without querying Doctrine or inspecting ad-hoc logs. The pipeline only wrote to the database and thumbnails, leaving no consumable artifact for data lakes or debugging snapshots.
- **Decision:** Added `MetaExportStage` to the ingestion pipeline right after the extractor stages, serialising the enriched `Media` entity into `media_index.meta` alongside each asset. Documented the schema (`docs/media-index-meta-schema.md`) and covered the behaviour with unit tests that validate the JSON payload.
- **Alternatives considered:** Generate exports via a separate CLI that queries persisted media (would miss in-flight updates and complicate dry-run verification) or piggy-back on database views (ties consumers to SQL schema changes and excludes filesystem-derived context). Both options were rejected because they either duplicated logic or failed to deliver the per-file artifact required by the ingest team.
- **Follow-up actions:** Monitor consumer pipelines for adoption, extend the schema when new signals land in `Media`, and wire the export directory into ingestion telemetry so operators can detect write failures quickly.

## 2025-02-15 – Persist memories clusters with spatial metadata
- **Author:** ChatGPT (gpt-5-codex)
- **Context:** Cluster-Strategien erzeugen inzwischen langlebige Geschichten mit Geometrie- und Highlight-Daten, doch das Projekt besaß keine persistente Ablage kompatibel zu PostGIS/JSONB. Ohne dedizierte Tabellen ließen sich wiederholbare Feed-Läufe, Debugging und Telemetrie-Korrelationen nicht durchführen.
- **Decision:** Neue Doctrine-Migration `Version20250215120000` erstellt, die `memories_cluster`, `memories_cluster_member` und `memories_significant_place` inklusive ENUM-Typen, JSONB-Metadaten sowie GIST/GIN-Indizes anlegt. Fremdschlüssel binden Cluster an bestehende `media`-Einträge und sorgen dafür, dass Mitglieder/Significant-Place-Datensätze konsistent cascaden.
- **Alternatives considered:** Eine generische Key/Value-Tabelle ohne Geometrie-Indizes (hätte räumliche Abfragen langsam gemacht) oder das Festhalten an reinem In-Memory-State im Clusterer (keine Migration, aber keine Wiederanlauf-Sicherheit). Beide Varianten verwarfen wir, weil sie komplexe Zeit-/Ortsfilter und nachvollziehbare Historien verhindert hätten.
- **Follow-up actions:** Nach dem Einspielen der Migration PostGIS-Verfügbarkeit in Zielumgebungen prüfen, die neuen Tabellen in ETL/Backup-Jobs aufnehmen und mittelfristig passende Repository-Services implementieren, damit die Anwendung auf die Struktur zugreifen kann.

## 2025-10-24 – Migration der Cluster-Overlays
- **Author:** ChatGPT (gpt-5-codex)
- **Context:** Die Einführung des kuratierten Overlays trennt erstmals rohe Mitgliedslisten von den Highlights, dennoch lagen in der Persistenz weiterhin gemischte Datensätze ohne Telemetrie vor. Operatoren mussten manuell interpretieren, wie viele Bilder pro Urlaub tatsächlich in der Kurationsschicht landeten.
- **Decision:** Neues Kommando `memories:cluster:migrate-curation` erstellt, das alle bestehenden Algorithmus/Fingerprint-Paare in einer Transaktion neu bewertet. `ClusterPersistenceService::refreshExistingCluster()` rehydriert die Drafts, übernimmt das Overlay in `member_quality.summary` und behält die Rohmitglieder unverändert. Lauf wird per Dry-Run testbar und dokumentiert in `docs/cluster-curation-migration.md`.
- **Alternatives considered:** Eine einmalige SQL-Migration ohne Service-Layer (hätte Qualitäts-/Personenmetriken und Coverwahl nicht rekonstruieren können) oder nur zukünftige Cluster aktualisieren (Bestandsdaten blieben inkonsistent). Beide Varianten wurden verworfen, weil sie Telemetrie-Lücken und doppelte Pflege von Roh-/Kurationslisten erzeugt hätten.
- **Follow-up actions:** Nach dem Rollout Urlaubs-Cluster mit ≥14 Tagen per Spotcheck prüfen und Monitoring beobachten, ob `member_quality.summary.curated_overlay_count` plausibel steigt. Bei Ausreißern erneuten Dry-Run mit Algorithmus-Filter fahren und Ursachen in den Selektionsprofilen analysieren.

## 2025-10-23 – Relax vacation selection caps for dense itineraries
- **Author:** ChatGPT (gpt-5-codex)
- **Context:** Telemetry from late-summer travel runs showed multi-stop days plateauing at four photos despite six high-quality shots clearing scene diversity and staypoint quotas. Operators compensated by forcing manual relaxations, which also lowered the pHash threshold and quality floor more than desired.
- **Decision:** Raised the `vacation` profile cap to six members per day, shortened the base spacing to 30 minutes, trimmed the pHash minimum to 9, and eased the quality floor to 0.50 so dense sightseeing days surface more variety without manual overrides. Updated selector tests confirm the relaxed policy keeps six distinct, high-scoring candidates while still rejecting sub-floor assets.
- **Alternatives considered:** Only adjust spacing (kept day cap too tight), or let the curator depend on runtime relaxations (kept telemetry noisy and risked over-relaxing pHash/quality thresholds). Both were rejected because they either still hid viable shots or eroded dedupe guarantees.
- **Follow-up actions:** Monitor `member_selection.rejections.day_quota` and `selection_profile.phash_min_hamming` metrics for regression. If duplicate density spikes, revisit pHash percentile tuning rather than tightening the global floor again.

## 2025-10-22 – Relax vacation away-distance profiles
- **Author:** ChatGPT (gpt-5-codex)
- **Context:** Vacation detection still missed compact weekend trips around dense metro areas because the adaptive away-distance profiles required 3+ home centres, tight radii, and 600–1,200 Medien pro Cluster.
- **Decision:** Lowered the default adaptive profile to trigger at 100 km with zwei Heimat-Zentren, 60 km Primärradius und rund 180 Medien und reduzierte das DACH-Profil auf 85 km Entfernung, zwei Zentren, 50 km Radius, Mindestdichte 4,0 sowie ca. 210 Medien, damit reale Kurzreisen erfasst werden.
- **Alternatives considered:** Only tweak the global `min_away_distance_km_default` (risked turning jede Landpartie in eine Urlaubsgeschichte) or add more granular telemetry alerts without touching thresholds (hätte keine Sofortwirkung auf die Auswahl gehabt).
- **Follow-up actions:** Beobachte Monitoring-Metriken `run_metrics.distance_km` und `run_metrics.members_core_total`, passe Dichte-/Mitglieder-Grenzen nach, falls vermehrt Pendler-Läufe als Urlaub markiert werden.

## 2025-10-21 – Tone-map slideshow backgrounds with EQ presets
- **Author:** ChatGPT (gpt-5-codex)
- **Context:** The default slideshow background remained visually flat after the vignette calibration, requiring manual EQ overrides to avoid washed-out skies while keeping skin tones natural.
- **Decision:** Nudged the baseline equaliser to `-0.03` brightness, `1.02` contrast, and `1.05` saturation, mirrored the defaults in `SlideshowVideoGenerator`, documented the look, and extended unit coverage to lock the FFmpeg filter string.
- **Alternatives considered:** Leave the neutral defaults and ask operators to tune per deployment, or hard-code a stronger LUT-style grade. Rejected because the former led to inconsistent exports across environments and the latter risked colour clipping on archival scans.
- **Follow-up actions:** Monitor rendered clips for edge cases (e.g. low-light scenes) and gather feedback on whether we need per-story presets or adaptive grading in the generator.

## 2025-10-20 – Calibrate slideshow vignette intensity
- **Author:** ChatGPT (gpt-5-codex)
- **Context:** The slideshow renderer derived the FFmpeg `vignette` setting from an angle formula, which produced darker than expected backgrounds and made it difficult to reason about overrides.
- **Decision:** Map `memories.slideshow.background_vignette_strength` directly to the FFmpeg `vignette` value, set the default to `0.35` (matching creative guidance), update documentation, and cover the behaviour with unit tests that inspect the generated filter graph.
- **Alternatives considered:** Keep the trigonometric mapping and simply tweak the default strength, or disable vignette shading altogether. Rejected because the angle calculation was unintuitive for operators and removing the vignette reduced perceived depth in recap videos.
- **Follow-up actions:** Gather feedback on whether deployments need per-story overrides and consider surfacing vignette presets in the UI once the direct mapping has been exercised in production.

## 2025-10-19 – Snap slideshow timing to a beat grid and expose loudness target
- **Author:** ChatGPT (gpt-5-codex)
- **Context:** Operators asked for consistent beat-matched slideshows and predictable loudness so generated clips can be dropped into social templates without manual trimming or audio leveling.
- **Decision:** Added an optional beat grid (`memories.slideshow.beat_grid_step`) that rounds each slide plus overlap to multiples such as 0.5 s or 0.6 s, refreshed the FFmpeg filter graph to chain `dynaudnorm`, `alimiter`, `aformat`, and fixed one-second fades, and documented the default -14 LUFS target via `memories.slideshow.audio_loudness_lufs`.
- **Alternatives considered:** Keep raw storyboard durations and rely on editors to retime clips, or normalise audio offline with loudness metadata. Rejected because it slowed delivery and produced inconsistent autoplay volumes across channels.
- **Follow-up actions:** Gather feedback on preferred raster values beyond 0.5 s/0.6 s and monitor peak levels after the limiter to confirm the -14 LUFS default works across music genres.

## 2025-10-18 – Grade slideshow backgrounds and overlay safe areas
- **Author:** ChatGPT (gpt-5-codex)
- **Context:** The slideshow renderer blurred backgrounds with a hard-coded gaussian filter and positioned overlays without safe-area guards, which produced halo artefacts on low-powered hardware (when blur was too expensive) and cramped text when subtitles and titles overlapped.
- **Decision:** Expose the blur algorithm, vignette toggle, and EQ settings via `memories.slideshow.background_*` parameters (with environment overrides) while adding `box` styling and fixed offsets to the text overlays so subtitles sit above the title and both respect the bottom margin.
- **Alternatives considered:** Keep the gaussian blur and rely on manual ffmpeg overrides, or move safe-area logic into the calling code. Rejected because operators need a simple parameter switch for box blur and centralising overlay offsets keeps typography consistent.
- **Follow-up actions:** Monitor rendering durations on constrained devices when switching to `boxblur` and collect feedback on the default vignette strength for potential adjustments.

## 2025-10-17 – Stabilise slideshow transitions
- **Author:** ChatGPT (gpt-5-codex)
- **Context:** Slideshow overlaps still drew pseudo-random durations per render and transition fallback sequences treated all `xfade` options equally, making fades appear as often as novelty effects like `pixelize`.
- **Decision:** Clamp every overlap to the resolved global `transitionDuration` (unless storyboard overrides apply) and bound it against the available slide overlap, eliminating random draws. The offset for each `xfade` stage now remains the cumulative sum of visible slide time minus the applied overlap so filter graphs stay aligned with the rendered timeline. Rework the deterministic transition chooser to weight cinematic fades higher than experimental wipes/pixel effects while seeding the randomizer with media and cluster identifiers for stable playback.【F:src/Service/Slideshow/SlideshowVideoGenerator.php†L92-L118】【F:src/Service/Slideshow/SlideshowVideoGenerator.php†L297-L335】【F:src/Service/Slideshow/SlideshowVideoGenerator.php†L708-L775】【F:src/Service/Slideshow/SlideshowVideoGenerator.php†L820-L910】
- **Alternatives considered:** Keep per-overlap random draws and rely on manual overrides, or shuffle transitions uniformly. Rejected because they broke predictability, produced hard cuts when the overlap draw exceeded slide content, and overused gimmick transitions in family recap videos.
- **Follow-up actions:** Observe telemetry for slide overlap saturation to ensure the clamp is sufficient and expand the weighting table if additional transitions are curated.

## 2025-10-16 – Slideshow fade configuration
- **Author:** ChatGPT (gpt-5-codex)
- **Context:** The slideshow renderer lacked dedicated controls for video fade-ins/-outs, leading to abrupt cuts at the start and
  end of exported clips and inconsistent overlays when multiple slides were blended.
- **Decision:** Added configurable intro/outro fade durations to the FFmpeg filter graph, applied them to both single-image
  slideshows and multi-image timelines, and exposed the values via `memories.slideshow.intro_fade_s` and
  `memories.slideshow.outro_fade_s`.
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
