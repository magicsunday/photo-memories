# Decision Log

## 2025-10-30 – Mirror scoring weight ENV names to configuration keys
- **Author:** ChatGPT (gpt-5-codex)
- **Context:** Operators adopted the new scoring weight overrides but the environment variables still used the singular `MEMORIES_SCORING_WEIGHT_*` naming, diverging from the pluralised `memories.scoring.weights.*` path. Tooling that auto-generated dashboards from the structured `memories.*` tree therefore produced mismatched ENV hints and confused deployments.
- **Decision:** Renamed the four scoring weight variables to `MEMORIES_SCORING_WEIGHTS_*` across `config/packages/memories.yaml`, `.env.dist`, and documentation, keeping defaults untouched so only the variable names change. Highlighted the updated identifiers in README and the configuration guide to steer operators through the migration.
- **Alternatives considered:** Keep legacy names and add aliases inside Symfony (risking double definitions and hiding drift) or rely on documentation footnotes (still left automation outputs inconsistent). Both were rejected because mirroring the configuration path keeps naming deterministic and simplifies tooling.
- **Follow-up actions:** Communicate the rename in the next release notes and monitor support channels for missed overrides; add compatibility shims only if multiple deployments struggle to update their environment definitions promptly.

## 2025-10-29 – Harmonise continuity, scoring, and slideshow ENV defaults
- **Author:** ChatGPT (gpt-5-codex)
- **Context:** Operators fine-tuning storyline continuity and slideshow pacing lacked a single source of truth: `.env.dist` recommended other values than the compiled defaults, docs still listed only legacy feature toggles, and support tools could not surface the new runtime keys.
- **Decision:** Added documented defaults for continuity thresholds, vacation minimum days, duplicate stack distance, scoring weights, and slideshow timing/zoom in `config/packages/memories.yaml`, mirrored the `%env()%` overrides in `.env.dist`, updated README tuning guidance, and extended `docs/configuration-files.md` with the full parameter list plus usage examples.
- **Alternatives considered:** Leave defaults scattered across `parameters.yaml` and README (kept ENV suggestions drifting from runtime behaviour) or rely solely on operator runbooks (raised the entry barrier for new clusters). Both options were rejected because they prolonged misaligned tuning and hindered support tooling.
- **Follow-up actions:** Monitor upcoming clustering runs for telemetry regressions when the new defaults ship, and revisit slideshow timings after the next music licensing review to confirm 3.5 s/0.75 s remain acceptable.

## 2025-10-28 – Capture motion-heavy clusters via liveliness heuristic
- **Author:** ChatGPT (gpt-5-codex)
- **Context:** Composite scoring treated video-heavy drafts identically to static photo sets, so stories with live photos, slow-motion clips, or high-motion bursts underperformed even when telemetry highlighted strong engagement with dynamic media.
- **Decision:** Introduced `LivelinessClusterScoreHeuristic` that blends video share, live photo coverage, and motion cues (blur, fps, duration, stabilisation) with configurable `%memories.score.liveliness.*%` thresholds. Registered the heuristic in the DI container, added a weighted slot to the composite scorer, and covered the behaviour with PHPUnit tests favouring motion-rich clusters.
- **Alternatives considered:** Reuse quality scoring (ignored motion metadata and overweighted sharp stills) or add post-score boosts in selection profiles (duplicated logic outside scoring, lacked persisted telemetry). Both were rejected because they hid the signal inside downstream heuristics instead of modelling liveliness directly.
- **Follow-up actions:** Monitor scoring telemetry for `liveliness_*` params after the next clustering run to validate weight defaults, and adjust the default thresholds once production data confirms typical motion blur and fps ranges.

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

## 2025-10-21 – Telemetrie, Explainability und deterministische Slideshows
- **Author:** ChatGPT (gpt-5-codex)
- **Context:** Monitoring-Logs enthielten bislang nur aggregierte Zähler, Explainability-Ausgaben fehlten komplett und Slideshow-Übergänge variieren zufällig zwischen Läufen, wodurch Reproduktionen und RCA-Analysen erschwert wurden.
- **Decision:** Strukturierte Telemetrie-Payloads um `decision_features`, `thresholds` und `final_decisions` erweitert, einen `ClusterModelCardWriter` samt `--explain`-Option implementiert, der pro Cluster HTML-„Model Cards“ unter `%memories.explain.model_card_dir%` ablegt, und einen deterministischen Seed (`memories.slideshow.seed`) eingeführt, der zufallsbasierte Übergangs-/Zoom-Auswahlen reproduzierbar macht.
- **Alternatives considered:** Status quo beibehalten und Analysen auf ad-hoc Dumps stützen (zu fehleranfällig), nur JSON-Dumps erzeugen statt HTML-Model-Cards (zu unhandlich für Nicht-Tech-Nutzer:innen) oder Seeds ausschließlich als Laufzeitparameter zulassen (erschwert Konfigurationsmanagement). Alle verworfen zugunsten einer integrierten Lösung mit konfigurierbaren Defaults.
- **Follow-up actions:** Beobachten, ob die erweiterten Logs volumenseitig unkritisch bleiben, bei Bedarf CI-Prüfungen für Model-Card-Markup ergänzen und evaluieren, ob weitere Zufallsquellen (z. B. Musikselektion) ebenfalls Seeds benötigen.

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

## 2025-10-18 – Introduce MMR re-ranking for member selection
- **Author:** ChatGPT (gpt-5-codex)
- **Context:** High-scoring burst shots and near-duplicate frames frequently displaced diverse content even after the hard pHash diversity stage, leaving storylines with repetitive imagery and little observability around the trade-offs.
- **Decision:** Added a Maximal Marginal Relevance pass to `PolicyDrivenMemberSelector` that balances raw scores with perceptual-hash similarity, exposed lambda/similarity/limit settings via selection profiles, and captured the iteration details in telemetry so analysts can trace why duplicates were demoted.【F:src/Service/Clusterer/Selection/PolicyDrivenMemberSelector.php†L231-L333】【F:config/parameters/selection.yaml†L6-L116】【F:docs/member-selection-telemetry.md†L110-L130】
- **Alternatives considered:** Rely solely on the existing pHash diversity stage or hard-drop candidates once a similarity cap is exceeded. Rejected because they either lacked nuance (dropping useful near-duplicates) or offered no per-run traceability into the scoring penalty.
- **Follow-up actions:** Monitor the new telemetry block for excessively aggressive penalties and consider adaptive lambda tuning per storyline if high-quality duplicates remain suppressed.

## 2025-10-18 – Preference-aware feed scoring and telemetry
- **Author:** ChatGPT (gpt-5-codex)
- **Context:** Algorithm opt-outs were previously handled with a hard drop inside the feed controller, so the response payload lacked telemetry explaining why scores changed and blocked strategies silently disappeared from pagination. Cluster heuristics also consumed static favourite lists, preventing boosts/penalties from reflecting current user preferences.
- **Decision:** Adjust feed scoring to apply a configurable penalty multiplier for opted-out algorithms while preserving telemetry that captures the applied multiplier and penalty context. Pass `FeedUserPreferences` into the feed builder and preference-aware heuristics so favourite persons/places provide score boosts and negative feedback applies penalties before pagination. Expose the enriched preference metadata (including algorithm penalties) in controller responses and cover the behaviour with unit tests.【F:src/Http/Controller/FeedController.php†L351-L430】【F:src/Service/Feed/MemoryFeedBuilder.php†L400-L474】【F:src/Service/Clusterer/Scoring/PeopleClusterScoreHeuristic.php†L17-L95】【F:src/Service/Clusterer/Scoring/LocationClusterScoreHeuristic.php†L1-L120】【F:test/Unit/Http/Controller/FeedControllerTest.php†L520-L706】
- **Alternatives considered:** Keep removing opted-out algorithms entirely (rejected because it hid content shifts from telemetry consumers) or leave heuristics unaware of dynamic preferences (rejected as it required redeploying configuration to adapt boosts/penalties).
- **Follow-up actions:** Monitor feed telemetry for large penalty multipliers to confirm penalties remain within acceptable ranges and extend integration coverage for the composite scorer preference path.

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
