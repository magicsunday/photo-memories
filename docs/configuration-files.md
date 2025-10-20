# Überblick über die Konfigurationsdateien

Dieses Dokument fasst die Konfigurationsdateien unter `config/` zusammen und erklärt, wofür die wichtigsten Parameter vorgesehen sind. Alle Werte lassen sich über Umgebungsvariablen anpassen, sofern sie in den YAML-Dateien mit `%env()%` referenziert werden.

## `config/parameters.yaml`

`parameters.yaml` bündelt globale Standardwerte für die Anwendung. Die Werte sind nach Funktionsbereichen gruppiert:

- **Indexierung** – Dateiendungen und Batchgrößen für die Medienaufnahme (`memories.index.*`).
  - Die Standardliste `memories.index.video_ext` umfasst jetzt `mp4`, `mov`, `m4v`, `3gp`, `hevc`, `webm`, `mkv` und `avi`. Damit
    erkennt die Indexierung gängige Video-Container bereits ohne lokale Overrides.
- **Videoverarbeitung** – Pfade und Defaults für `ffmpeg`/`ffprobe` sowie das Posterframe (`memories.video.*`).
- **Metadaten-Pipeline** – Aktivierung von Telemetrie sowie optionale Verarbeitungsschritte (`memories.metadata.pipeline.*`).
- **Gesichtserkennung** – Binärpfade, Klassifizierer und Erkennungsparameter (`memories.face_detection.*`).
- **Hashing** – Einstellungen für Wahrnehmungshashes (`memories.hash.*`).
- **Heimatregion** – Referenzkoordinaten und Suchradien für „Zuhause“-Logik (`memories.home.*`). `MEMORIES_HOME_LAT`, `MEMORIES_HOME_LON` und `MEMORIES_HOME_RADIUS_KM` sind verpflichtend zu setzen; bleiben sie auf `0/0`, meldet `memories:cluster` eine Warnung im Telemetrie-Block sowie in der Urlaub-Debug-Ausgabe.
- **Geocoding** – Zugriffspunkte, Drosselung und erlaubte POI-Typen für Nominatim/Overpass (`memories.geocoding.*`).
- **Zeit & Cluster** – Zeitzonen, Zeitnormalisierung und Transportgeschwindigkeitsgrenzen (`memories.time.*`, `memories.cluster.transport_speed.*`).
- **Staypoint-Erkennung** – adaptiver Radius (0,18–0,35 km), Aufenthaltsdauer (15–25 min) und DBSCAN-Fallback der Aufenthaltsort-Erkennung (`memories.cluster.staypoint.*`).
- **Urlaubs- und Kohortenclustering** – Schwellenwerte und Personenlisten zur Bildung von Clustern (`memories.cluster.vacation.*`, `memories.cluster.cohort.*`). Standardmäßig gelten 140 km Mindestentfernung vom Zuhause, vier Medien pro Tag und mindestens drei Abwesenheitstage (`MEMORIES_CLUSTER_VACATION_MIN_AWAY_DISTANCE_KM`, `MEMORIES_CLUSTER_VACATION_MIN_ITEMS_PER_DAY`, `MEMORIES_CLUSTER_VACATION_MIN_AWAY_DAYS`). Das adaptive Urlaubsprofil reduziert den Away-Abstand bei dicht bebauten Heimatregionen auf 100 km, akzeptiert bereits zwei Heimat-Zentren, erweitert den Primärradius auf 60 km und lässt Cluster mit rund 180 Medien zu. Für DACH-Profile (`countries: ['de','at','ch']`) greifen 85 km Entfernung, ebenfalls zwei Zentren, maximal 50 km Primärradius, eine Mindestdichte von 4,0 und mindestens ca. 210 Medien.
- **Thumbnails** – Zielgrößen und Orientierungsbehandlung (`memories.thumbnail_*`).
- **Konsolidierung & Prioritäten** – Gewichtungen und Reihenfolgen für Clusterstrategien (`memories.cluster.consolidate.*`, `memories.cluster.priority.*`). Die Prioritäten folgen nun einer gestuften Reihenfolge (`vacation` → `weekend_getaways_over_years` → `year_in_review` → `monthly_highlights` → `on_this_day_over_years`/`this_month_over_years`/`one_year_ago` → Szenen-Cluster wie `person_cohort`, `anniversary`, `holiday_event`, `cityscape_night`, `season`, `golden_hour`, `snow_day`, `day_album`, `at_home_weekend`, `at_home_weekday` → Geräte- und Ähnlichkeitsalgorithmen `location_similarity`, `cross_dimension`, `time_similarity`, `photo_motif`, `panorama`, `panorama_over_years`, `portrait_orientation`, `video_stories`, `device_similarity`, `phash_similarity`, `burst`). `device_similarity` bleibt zudem Bestandteil von `memories.cluster.consolidate.annotate_only` und der `min_unique_share`-Sonderbehandlung.
  - Temporär für den anstehenden Debug-Lauf sind die Konsolidierungs-Schwellen bewusst gelockert: `memories.cluster.consolidate.min_score` steht auf `0.25`, `memories.cluster.consolidate.min_size` auf `2` und `memories.cluster.consolidate.require_valid_time` ist deaktiviert. Zusätzlich verlangt `memories.cluster.consolidate.min_unique_share.burst` jetzt `0.35`. Das Pro-Medium-Limit `memories.cluster.consolidate.per_media_cap` wurde nach der Testphase wieder auf `2` reduziert, damit einzelne Medien höchstens zwei konsolidierte Ergebnisse besetzen. In `parameters.yaml` ist das weiterhin als TODO markiert; nach Abschluss der Validierung die restlichen Werte wieder verschärfen.
- **Transit-Erkennung** – `memories.cluster.transit.threshold_profiles` beschreibt mehrere Schwellenprofile für Reise- bzw. Transit-Tage. Das `default`-Profil markiert nun bereits Tagesläufe ab rund 50 km mit mindestens drei GPS-bepunkteten Medien, behält aber die bestehenden Geschwindigkeits- (`min_segment_speed_mps` = 5.0, `min_fast_segments` = 3) sowie Heading-Grenzen (`max_heading_change_deg` = 90.0, `min_consistent_heading_segments` = 2) unverändert bei.【F:config/parameters.yaml†L625-L636】
- **Cluster-Persistenz** – Begrenzung der Mitgliedermenge pro Cluster und die Größe der Fingerabdruck-Abfragen (`memories.cluster.persistence.max_members`, `memories.cluster.persistence.fingerprint_lookup_batch_size`). Letzteres definiert, wie viele Fingerprints Doctrine pro Abfrage in `IN`-Klauseln bündelt (Standard: 500), um riesige Parameterlisten zu vermeiden.
  - Achtet darauf, dass Deployments mindestens `MEMORIES_CLUSTER_MAX_MEMBERS=300` exportieren (die Vorlage `.env.dist` setzt `500`). So verhindern wir, dass große Cluster trotz der gelockerten Konsolidierungsschwellen beim Persistieren abgeschnitten werden.
- **Scoring** – Basiswerte und POI-spezifische Verstärkungen zur Qualitätsbewertung (`memories.score.*`).
  - `memories.score.poi_category_boosts` priorisiert ausgewählte OSM-Kategorien (Museen, Galerien, Freizeitparks usw.) beim Scoring.
  - `memories.score.poi.iconic_boost` addiert einen Bonus, sobald ein Cluster eine dominante Szene (z. B. Community-Motive) aufweist.
  - `memories.score.poi.iconic_similarity_threshold` legt den erforderlichen Dominanz-/Ähnlichkeitswert (0–1) für den Bonus fest.
  - `memories.score.poi.iconic_signatures` hinterlegt pHash-Signaturen bekannter Community-Szenen, die beim Boosting berücksichtigt werden.
  - `memories.score.liveliness.*` bewertet, wie bewegungsstark ein Cluster wirkt. `video_share_weight`, `live_photo_share_weight` und `motion_weight` definieren die Gewichtung der Teilkomponenten, während `video_share_target`, `live_photo_share_target` und `motion_share_target` festlegen, ab welchem Anteil Videos, Live Photos oder Motion-Cues voll ausschlagen. `motion_blur_threshold`/`motion_blur_target` steuern die Fotoauswertung, `motion_video_duration_threshold` und `motion_video_fps_threshold` markieren bewegte Clips, `motion_coverage_weight` balanciert Deckung und Intensität.
- **Explainability** – Das Verzeichnis für die HTML-„Model Cards“, die `memories:curate --explain` pro Cluster erzeugt (`memories.explain.model_card_dir`). Der Default `%kernel.project_dir%/var/log/memories/model-cards` lässt sich über `MEMORIES_MODEL_CARD_DIR` überschreiben; Operatoren sollten das Zielverzeichnis auf persistente Storage-Pfade legen, wenn mehrere Läufe verglichen werden sollen.
- **Slideshow** – Verzeichnisse, Laufzeiten, Übergänge sowie Schrifteinstellungen der Slideshow-Funktion (`memories.slideshow.*`).

  Die Übergänge aus `memories.slideshow.transitions` spiegeln die kuratierte Auswahl stabiler `xfade`-Effekte wider und
  enthalten standardmäßig die Kategorien Crossfade (`fade`, `dissolve`, `fadeblack`, `fadewhite`), Push (`pushleft`,
  `pushright`, `pushup`, `pushdown`), Wipe (`wipeleft`, `wiperight`, `wipeup`, `wipedown`), Slide (`slideleft`,
  `slideright`, `slideup`, `slidedown`) und Zoom (`zoom`). Über `memories.slideshow.transitions_unstable_enabled` können
  zusätzliche, als experimentell markierte Effekte wie `circleopen`, `pixelize` oder `distance` explizit freigeschaltet
  werden, sofern sie auch in `memories.slideshow.transitions` gelistet sind. Die Liste lässt sich erweitern oder
  reduzieren; der Slideshow-Manager trimmt Eingaben und reicht sie unverändert an FFmpeg weiter.

  Storyboard-Vorschauen landen unter `memories.slideshow.storyboard_dir` (Default: `%kernel.project_dir%/var/memories`).
  Für jeden Dry-Run erzeugt der Manager dort `<item-id>/storyboard.json`. Die Ablage lässt sich über
  `MEMORIES_SLIDESHOW_STORYBOARD_DIR` anpassen; Auslöser sind entweder der HTTP-Query-Parameter `dry-run=1` auf
  `/api/feed/{id}/video` oder die neue CLI-Option `slideshow:generate --dry-run`, die nur das Storyboard schreibt und kein
  Video rendert.

  Für die Ein- und Ausblendung der Clips stehen `memories.slideshow.intro_fade_s` (Fade-In ab Sekunde 0) sowie
  `memories.slideshow.outro_fade_s` (Fade-Out beginnend bei Gesamtdauer minus Wert) zur Verfügung. Beide Werte greifen
  sowohl bei Einzelbild-Slideshows als auch bei Übergangssequenzen über mehrere Bilder hinweg.

  Die Animationsgeschwindigkeit kontrollieren `memories.slideshow.fps` (Bildrate des Exportvideos) und
  `memories.slideshow.easing` (Kurve für Ken-Burns-Zoom/Pan, z. B. `cosine`, `linear`, `smoothstep`, `sine` oder `quadratic`).
  Der Startzoom `memories.slideshow.zoom_start` beginnt standardmäßig bei `1.03` und wird – ebenso wie `memories.slideshow.zoom_end`
  – im Generator auf mindestens `1.03` geklemmt, damit leichte Bewegungen erhalten bleiben. Werte oberhalb von etwa `1.25` wirken
  in der Praxis schnell zu aggressiv, sollten also nur mit Vorsicht eingesetzt werden. Für rhythmische Storyboards kann
  `memories.slideshow.beat_grid_step` genutzt werden. Ein Wert wie `0.5` oder `0.6` rundet die Summe aus Bildlaufzeit und
  Übergangsdauer auf Vielfache des gewählten Taktrasters. Der Standard `0.0` deaktiviert das Feature; über
  `MEMORIES_SLIDESHOW_BEAT_GRID_STEP` lässt sich der Rasterwert pro Umgebung überschreiben.

  Die effektive Dauer einzelner Slides orientiert sich am Basiswert `memories.slideshow.image_duration_s` und streut optional
  mithilfe von `memories.slideshow.image_duration_jitter_lower_s` sowie `memories.slideshow.image_duration_jitter_upper_s`.
  Beide Parameter geben an, wie stark der Manager die Laufzeit pro Bild nach unten bzw. oben abwandeln darf (in Sekunden).
  Die Übergangszeiten folgen dem gleichen Schema: `memories.slideshow.transition_duration_s` dient als Grundwert, während
  `memories.slideshow.transition_duration_jitter_lower_s` und `memories.slideshow.transition_duration_jitter_upper_s` das
  zufällige Delta begrenzen. Setzen Sie die jeweiligen Jitter-Werte auf `0`, um deterministische Storyboards ohne Zufallsanteil
  zu erhalten.

  Die Hintergrundgestaltung lässt sich mit `memories.slideshow.background_blur_sigma` (Stärke),
  `memories.slideshow.background_blur_filter` (`gblur` für Qualität, `boxblur` für Performance), dem zusätzlichen
  `memories.slideshow.background_boxblur_enabled` (optional zweiter Boxblur-Durchgang),
  `memories.slideshow.background_vignette_enabled` (Schattierung) samt `memories.slideshow.background_vignette_strength`
  (1:1 auf den FFmpeg-Parameter `vignette=<wert>` gemappt; der Default `0.35` entspricht der bisherigen Intensität)
  sowie den Equalizer-Werten `memories.slideshow.background_eq_brightness`, `memories.slideshow.background_eq_contrast` und
  `memories.slideshow.background_eq_saturation` feintunen. Standardmäßig sorgt der Mix aus `-0.03` Helligkeit,
  `1.02` Kontrast und `1.05` Sättigung für etwas mehr Punch, ohne Gesichter auszublasen. Über die Umgebungsvariablen
  `MEMORIES_SLIDESHOW_BACKGROUND_BLUR_FILTER`, `MEMORIES_SLIDESHOW_BACKGROUND_BOXBLUR`,
  `MEMORIES_SLIDESHOW_BACKGROUND_VIGNETTE`, `MEMORIES_SLIDESHOW_BACKGROUND_VIGNETTE_STRENGTH`,
  `MEMORIES_SLIDESHOW_BACKGROUND_EQ_BRIGHTNESS`, `MEMORIES_SLIDESHOW_BACKGROUND_EQ_CONTRAST` und
  `MEMORIES_SLIDESHOW_BACKGROUND_EQ_SATURATION` lassen sich diese Vorgaben pro Deployment überschreiben.

  Die Textgestaltung nutzt `memories.slideshow.text_box_enabled`, um halbtransparente Boxen hinter Titeln und Untertiteln
  zu aktivieren oder auszublenden; Schriften werden weiterhin über `memories.slideshow.font_family` und
  `memories.slideshow.font_file` gewählt.

  Die Audiosektion normalisiert die Musikspur mit -14 LUFS als Zielwert. Wer andere Plattformvorgaben bedienen muss, passt
  `memories.slideshow.audio_loudness_lufs` beziehungsweise `MEMORIES_SLIDESHOW_AUDIO_LOUDNESS` an; der FFmpeg-Filter zieht
  anschließend Dynamikkompression (`dynaudnorm`), einen Limiter (`alimiter`), ein auf 48 kHz Stereo fixiertes Format
  (`aformat`) und 1-Sekunden-Fades (`afade`) automatisch nach.

  Für reproduzierbare Übergangs- und Zoomfolgen kann `memories.slideshow.seed` gesetzt werden. Der Parameter übernimmt einen
  deterministischen Seed, der vom Manager über den Generator bis zum `TransitionSequenceGenerator` weitergereicht wird und
  so zufällige Auswahlentscheidungen (Transitions, Zoom-Offsets, Ken-Burns-Startpunkte) stabil hält. Bleibt der Wert leer
  (Default), verhält sich die Pipeline wie bisher und erzeugt pro Lauf frische Varianten. Konfigurationen mit mehreren
  Operator:innen sollten sich auf einen gemeinsamen Seed einigen, wenn Storyboards zwischen Umgebungen verglichen werden.

Die meisten Parameter besitzen einen `*_default`-Wert, der über eine gleichnamige Umgebungsvariable (z. B. `MEMORIES_HOME_RADIUS_KM`) übersteuert werden kann.

## `config/packages/memories.yaml`

Dieses Paket bündelt zur Laufzeit überschreibbare Parameter und Feature-Toggles, die via `%env()%` an `.env` gekoppelt sind. Jeder Eintrag besitzt ein `*_default`, das den YAML-Ausgangswert dokumentiert, sowie einen `value`/`enabled`-Schlüssel für den effektiven Wert inklusive ENV-Overrides. Der strukturierte Block unter `memories.*` fasst alle Defaults, aktiven Werte und ENV-Namen für Support-Tools zusammen.

- **Kontinuitätsgrenzen (`memories.thresholds.*`)** – `time_gap_hours_default` (2.0 h) trennt Storylines nach größeren Zeitlücken; `space_gap_meters_default` (250 m) entscheidet über räumliche Clustertrennung. Beide Werte lassen sich über `MEMORIES_THRESHOLDS_TIME_GAP_HOURS` bzw. `MEMORIES_THRESHOLDS_SPACE_GAP_METERS` justieren.
- **Urlaubsdauer (`memories.vacation.min_days`)** – orientiert sich am Basisschwellwert aus `parameters.yaml` (3 Tage) und wird bei Bedarf mit `MEMORIES_VACATION_MIN_DAYS` verlängert oder verkürzt.
- **Dubletten-Stacking (`memories.dupstack.hamming_max`)** – begrenzt den maximalen Hamming-Abstand für Dublettencluster (Default 9). Deployments mit engeren/lockereren Dublettenprüfungen setzen `MEMORIES_DUPSTACK_HAMMING_MAX`.
- **Scoring-Gewichte (`memories.scoring.weights.*`)** – verteilen den Gesamt-Score auf Qualität (0.22), Relevanz (0.45), Lebendigkeit (0.08) und Diversität (0.25). Über `MEMORIES_SCORING_WEIGHTS_*` lassen sich Prioritäten verschieben, etwa für dynamischere Feeds.
- **Slideshow-Laufzeiten (`memories.slideshow.*`)** – stellen die Basiswerte für Storyboard-Tempo und Ken-Burns-Zoom bereit: `duration_per_image_default` (3.5 s), `transition_duration_default` (0.75 s), `zoom_min_default` (1.03) und `zoom_max_default` (1.08). Mit `MEMORIES_SLIDESHOW_DURATION_PER_IMAGE`, `MEMORIES_SLIDESHOW_TRANSITION_DURATION`, `MEMORIES_SLIDESHOW_ZOOM_MIN` und `MEMORIES_SLIDESHOW_ZOOM_MAX` passen Operator:innen Tempo und Zoom-Verlauf an Musik & Ausspielkanal an.
- **Feature-Toggles (`memories.features.*`)** – behalten die bisherigen Schalter für Saliency-Cropping (`MEMORIES_FEATURE_SALIENCY_CROPPING`) und den Storyline-Generator (`MEMORIES_FEATURE_STORYLINE_GENERATOR`).

Ein Beispiel für lokale Overrides in `.env.local`:

```env
# Kontinuitäts- und Slideshow-Tuning für Debug-Läufe
MEMORIES_THRESHOLDS_TIME_GAP_HOURS=1.5
MEMORIES_SLIDESHOW_DURATION_PER_IMAGE=4.0
MEMORIES_SLIDESHOW_ZOOM_MAX=1.12
```

### Auswahlprofile (`memories.cluster.selection.profile_values`)

- Sämtliche Story-Profile (z. B. `vacation_weekend_transit`, `location`, `scene`, `device`, `this_day_month`, `people_friends`, `highlights`) definieren den kompletten Optionssatz, den der `SelectionPolicyProvider` verarbeitet – inklusive `min_spacing_seconds`, `phash_min_hamming`, `max_per_staypoint`, `max_per_staypoint_relaxed`, `video_bonus`, `face_bonus`, `selfie_penalty`, `quality_floor`, `minimum_total`, `max_per_year`, `max_per_bucket`, `video_heavy_bonus` sowie den neuen MMR-Parametern `mmr_lambda`, `mmr_similarity_floor`, `mmr_similarity_cap` und `mmr_max_results`.
- `mmr_lambda` (0 – 1) gewichtet den Zielscore gegenüber der Ähnlichkeitsstrafe, während `mmr_similarity_floor` und `mmr_similarity_cap` den Bereich definieren, in dem Perceptual-Hash-Similarität überhaupt in die Strafe einfließt. `mmr_max_results` begrenzt, wie viele Kandidaten in die Maximal-Marginal-Relevance-Nachbewertung einfließen – hilfreich, um Hotspots mit sehr vielen Treffern kontrolliert zu entdoppeln.
- Das Standortprofil (`location`) hebt die Zielmenge auf 48 Medien an, erlaubt vier Picks pro Tag und arbeitet mit Drei-Stunden-Slots sowie 3.000 Sekunden Mindestabstand. Die Qualitätsuntergrenze bleibt bei `quality_floor` = 0,55; `video_bonus` = 0,24 und `face_bonus` = 0,32 sorgen weiterhin für ein ausgewogenes Verhältnis zwischen Clips und Porträts.
- Das Highlights-Profil (`highlights`) kuratiert jetzt 30 Medien bei `phash_min_hamming` = 10, reduziert die Selfie-Strafung auf 0,24 und stärkt Videos (`video_bonus` = 0,38) sowie Gesichter (`face_bonus` = 0,36), um Storys mit starker Dynamik zu bevorzugen. Der Mindestabstand steigt auf 3.300 Sekunden, um Streuung zwischen den Picks zu fördern.
- Das Urlaubsprofil (`vacation`) setzt zunächst `max_per_staypoint` = 1 und kann sich bei Bedarf automatisch auf zwei Stücke pro Aufenthaltsort erweitern (`max_per_staypoint_relaxed` = 2), bevor sämtliche Kappungen entfallen.
- Werte lassen sich pro Umgebung über `config/parameters.local.yaml` oder eine eigene Parameter-Datei anpassen. Für die globalen Defaults bleiben die Umgebungsvariablen `MEMORIES_CLUSTER_SELECTION_TARGET_TOTAL`, `MEMORIES_CLUSTER_SELECTION_MAX_PER_DAY`, `MEMORIES_CLUSTER_SELECTION_TIME_SLOT_HOURS`, `MEMORIES_CLUSTER_SELECTION_MIN_SPACING_SECONDS`, `MEMORIES_CLUSTER_SELECTION_PHASH_MIN_HAMMING`, `MEMORIES_CLUSTER_SELECTION_MAX_PER_STAYPOINT`, `MEMORIES_CLUSTER_SELECTION_VIDEO_BONUS`, `MEMORIES_CLUSTER_SELECTION_FACE_BONUS`, `MEMORIES_CLUSTER_SELECTION_SELFIE_PENALTY` und `MEMORIES_CLUSTER_SELECTION_QUALITY_FLOOR` zuständig.
- Temporäre Anpassungen bei Clustering-Läufen erfolgen über die bekannten CLI-Optionen `--sel-target-total`, `--sel-max-per-day`, `--sel-min-spacing`, `--sel-phash-hamming` sowie `--sel-max-staypoint`. Die Overrides greifen für sämtliche Profile der Laufzeitinstanz.

## `config/parameters/`

Spezielle Parametergruppen sind in eigenen Dateien ausgelagert und werden durch `parameters.yaml` eingebunden.

### `parameters/feed.yaml`

Definiert alles rund um Feed-Personalisierung und API-Limits:

- Score-, Mengen- und Qualitätsgrenzen für Stories (`memories.feed.*`).
- Vorgaben für unterschiedliche Profile wie `default`, `familienfreundlich` oder `reisen`.
- HTTP-API-Limits und Miniaturansichten (`memories.http.feed.*`).
- Benachrichtigungskanäle inkl. Zeitzone und Sendezeit (`memories.feed.notifications.*`).
- SPA-spezifische Konfiguration wie Gesten, Offline-Caching und Animationen (`memories.spa.*`).

### `parameters/monitoring.yaml`

Steuert, ob das Monitoring aktiv ist und wohin Job-Logs geschrieben werden (`memories.monitoring.*`).

## `config/services.yaml`

Diese Datei registriert Services im Symfony-DI-Container. Sie nutzt Autowiring/-konfiguration für den Namespace `MagicSunday\Memories\` und ergänzt gezielt Service-Definitionen. Wichtige Abschnitte:

- **Metadaten-Extraktoren** – Reihenfolge und Parameter der Extraktionsschritte (Tag `memories.metadata_extractor`).
  - Der `ExifMetadataExtractor` liest standardmäßig auch bei Videos EXIF-Daten (`$readExifForVideos = true`).
- **Gesichtserkennung & Qualität** – Service-Bindungen für Backends und Aggregatoren.
- **Clusterer & Ranking** – Strategien, Prioritäten und Konsolidierungsregeln (`MagicSunday\Memories\Service\Clusterer\*`).
- **Feed-Services** – Builder, Personalisierungsprofile und HTTP-Controller (`MagicSunday\Memories\Service\Feed\*`, `FeedController`).
- **Slideshow & Thumbnail** – Pfade, Übergaben und Dienstschnittstellen.
- **Sonstige Bindungen** – Repository-Aliase, Event-Listener und Helfer (`Support`, `Repository`, `Http`).

Beim Hinzufügen neuer Services sollten die benötigten Parameter in `parameters.yaml` abgelegt und – falls konfigurierbar – über `%env()%` exponiert werden.

## `config/templates/titles.yaml`

Hinterlegt lokalisierte Titel- und Untertitelvorlagen für Cluster-Kategorien. Platzhalter wie `{{ date_range }}`, `{{ place }}` oder `{{ year }}` werden zur Laufzeit ersetzt. Die Datei ist nach Strategien gruppiert (z. B. `time_similarity`, `year_in_review`) und aktuell in Deutsch verfügbar. Weitere Sprachen können durch zusätzliche Wurzelknoten (z. B. `en:`) ergänzt werden.

## Ergänzende Hinweise

- Beim Container-Build protokolliert der `DuplicateParameterGuardCompilerPass` eine Warnung, wenn Parameter mehrfach definiert werden – sei es über doppelte Schlüssel innerhalb einer Datei oder über mehrere eingebundene Parameterpfade. Konsolidiere solche Werte, damit klar ist, welche Defaults tatsächlich greifen.
- Änderungen an Konfigurationswerten sollten im Commit erläutert und – falls sie das Verhalten nach außen beeinflussen – in der README dokumentiert werden.
- Nach Anpassungen empfiehlt es sich, den Cache unter `var/cache/` zu leeren, damit Symfony die Container-Definitionen neu generiert.
- Neue Umgebungsvariablen müssen in Deployment-Umgebungen gesetzt werden, bevor sie in Produktion genutzt werden.
