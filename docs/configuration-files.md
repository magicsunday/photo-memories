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
- **Heimatregion** – Referenzkoordinaten und Suchradien für „Zuhause“-Logik (`memories.home.*`).
- **Geocoding** – Zugriffspunkte, Drosselung und erlaubte POI-Typen für Nominatim/Overpass (`memories.geocoding.*`).
- **Zeit & Cluster** – Zeitzonen, Zeitnormalisierung und Transportgeschwindigkeitsgrenzen (`memories.time.*`, `memories.cluster.transport_speed.*`).
- **Staypoint-Erkennung** – adaptiver Radius (0,18–0,35 km), Aufenthaltsdauer (15–25 min) und DBSCAN-Fallback der Aufenthaltsort-Erkennung (`memories.cluster.staypoint.*`).
- **Urlaubs- und Kohortenclustering** – Schwellenwerte und Personenlisten zur Bildung von Clustern (`memories.cluster.vacation.*`, `memories.cluster.cohort.*`). Standardmäßig gelten 140 km Mindestentfernung vom Zuhause, vier Medien pro Tag und mindestens zwei Abwesenheitstage (`MEMORIES_CLUSTER_VACATION_MIN_AWAY_DISTANCE_KM`, `MEMORIES_CLUSTER_VACATION_MIN_ITEMS_PER_DAY`, `MEMORIES_CLUSTER_VACATION_MIN_AWAY_DAYS`). Das adaptive Urlaubsprofil reduziert den Away-Abstand bei dicht bebauten Heimatregionen auf 100 km, akzeptiert bereits zwei Heimat-Zentren, erweitert den Primärradius auf 60 km und lässt Cluster mit rund 180 Medien zu. Für DACH-Profile (`countries: ['de','at','ch']`) greifen 85 km Entfernung, ebenfalls zwei Zentren, maximal 50 km Primärradius, eine Mindestdichte von 4,0 und mindestens ca. 210 Medien.
- **Thumbnails** – Zielgrößen und Orientierungsbehandlung (`memories.thumbnail_*`).
- **Konsolidierung & Prioritäten** – Gewichtungen und Reihenfolgen für Clusterstrategien (`memories.cluster.consolidate.*`, `memories.cluster.priority.*`). Die Prioritäten folgen nun einer gestuften Reihenfolge (`vacation` → `weekend_getaways_over_years` → `year_in_review` → `monthly_highlights` → `on_this_day_over_years`/`this_month_over_years`/`one_year_ago` → Szenen-Cluster wie `person_cohort`, `anniversary`, `holiday_event`, `cityscape_night`, `season`, `golden_hour`, `snow_day`, `day_album`, `at_home_weekend`, `at_home_weekday` → Geräte- und Ähnlichkeitsalgorithmen `location_similarity`, `cross_dimension`, `time_similarity`, `photo_motif`, `panorama`, `panorama_over_years`, `portrait_orientation`, `video_stories`, `device_similarity`, `phash_similarity`, `burst`). `device_similarity` bleibt zudem Bestandteil von `memories.cluster.consolidate.annotate_only` und der `min_unique_share`-Sonderbehandlung.
- **Cluster-Persistenz** – Begrenzung der Mitgliedermenge pro Cluster und die Größe der Fingerabdruck-Abfragen (`memories.cluster.persistence.max_members`, `memories.cluster.persistence.fingerprint_lookup_batch_size`). Letzteres definiert, wie viele Fingerprints Doctrine pro Abfrage in `IN`-Klauseln bündelt (Standard: 500), um riesige Parameterlisten zu vermeiden.
- **Scoring** – Basiswerte und POI-spezifische Verstärkungen zur Qualitätsbewertung (`memories.score.*`).
- **Slideshow** – Verzeichnisse, Laufzeiten, Übergänge sowie Schrifteinstellungen der Slideshow-Funktion (`memories.slideshow.*`).

  Die Übergänge aus `memories.slideshow.transitions` spiegeln die kuratierte Auswahl des `xfade`-Filters wider und enthalten
  standardmäßig `fade`, `dissolve`, `fadeblack`, `fadewhite`, `wipeleft`, `wiperight`, `wipeup`, `wipedown`, `slideleft`,
  `slideright`, `smoothleft`, `smoothright`, `circleopen`, `circleclose`, `radial`, `hlslice`, `vuslice`, `distance` und
  `pixelize`. Die Liste kann beliebig erweitert oder reduziert werden; der Slideshow-Manager trimmt Eingaben und reicht sie
  unverändert an FFmpeg weiter.

  Für die Ein- und Ausblendung der Clips stehen `memories.slideshow.intro_fade_s` (Fade-In ab Sekunde 0) sowie
  `memories.slideshow.outro_fade_s` (Fade-Out beginnend bei Gesamtdauer minus Wert) zur Verfügung. Beide Werte greifen
  sowohl bei Einzelbild-Slideshows als auch bei Übergangssequenzen über mehrere Bilder hinweg.

  Die Animationsgeschwindigkeit kontrollieren `memories.slideshow.fps` (Bildrate des Exportvideos) und
  `memories.slideshow.easing` (Kurve für Ken-Burns-Zoom/Pan, z. B. `cosine`, `linear`, `smoothstep`, `sine` oder `quadratic`).
  Für rhythmische Storyboards kann `memories.slideshow.beat_grid_step` genutzt werden. Ein Wert wie `0.5` oder `0.6` rundet die
  Summe aus Bildlaufzeit und Übergangsdauer auf Vielfache des gewählten Taktrasters. Der Standard `0.0` deaktiviert das Feature;
  über `MEMORIES_SLIDESHOW_BEAT_GRID_STEP` lässt sich der Rasterwert pro Umgebung überschreiben.

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

Die meisten Parameter besitzen einen `*_default`-Wert, der über eine gleichnamige Umgebungsvariable (z. B. `MEMORIES_HOME_RADIUS_KM`) übersteuert werden kann.

### Auswahlprofile (`memories.cluster.selection.profile_values`)

- Sämtliche Story-Profile (z. B. `vacation_weekend_transit`, `location`, `scene`, `device`, `this_day_month`, `people_friends`, `highlights`) definieren den kompletten Optionssatz, den der `SelectionPolicyProvider` verarbeitet – inklusive `min_spacing_seconds`, `phash_min_hamming`, `max_per_staypoint`, `max_per_staypoint_relaxed`, `video_bonus`, `face_bonus`, `selfie_penalty`, `quality_floor`, `minimum_total`, `max_per_year`, `max_per_bucket` und `video_heavy_bonus`.
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
