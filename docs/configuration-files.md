# Überblick über die Konfigurationsdateien

Dieses Dokument fasst die Konfigurationsdateien unter `config/` zusammen und erklärt, wofür die wichtigsten Parameter vorgesehen sind. Alle Werte lassen sich über Umgebungsvariablen anpassen, sofern sie in den YAML-Dateien mit `%env()%` referenziert werden.

## `config/parameters.yaml`

`parameters.yaml` bündelt globale Standardwerte für die Anwendung. Die Werte sind nach Funktionsbereichen gruppiert:

- **Indexierung** – Dateiendungen und Batchgrößen für die Medienaufnahme (`memories.index.*`).
- **Videoverarbeitung** – Pfade und Defaults für `ffmpeg`/`ffprobe` sowie das Posterframe (`memories.video.*`).
- **Metadaten-Pipeline** – Aktivierung von Telemetrie sowie optionale Verarbeitungsschritte (`memories.metadata.pipeline.*`).
- **Gesichtserkennung** – Binärpfade, Klassifizierer und Erkennungsparameter (`memories.face_detection.*`).
- **Hashing** – Einstellungen für Wahrnehmungshashes (`memories.hash.*`).
- **Heimatregion** – Referenzkoordinaten und Suchradien für „Zuhause“-Logik (`memories.home.*`).
- **Geocoding** – Zugriffspunkte, Drosselung und erlaubte POI-Typen für Nominatim/Overpass (`memories.geocoding.*`).
- **Zeit & Cluster** – Zeitzonen, Zeitnormalisierung und Transportgeschwindigkeitsgrenzen (`memories.time.*`, `memories.cluster.transport_speed.*`).
- **Urlaubs- und Kohortenclustering** – Schwellenwerte und Personenlisten zur Bildung von Clustern (`memories.cluster.vacation.*`, `memories.cluster.cohort.*`).
- **Thumbnails** – Zielgrößen und Orientierungsbehandlung (`memories.thumbnail_*`).
- **Konsolidierung & Prioritäten** – Gewichtungen und Reihenfolgen für Clusterstrategien (`memories.cluster.consolidate.*`, `memories.cluster.priority.*`).
- **Scoring** – Basiswerte und POI-spezifische Verstärkungen zur Qualitätsbewertung (`memories.score.*`).
- **Slideshow** – Verzeichnisse, Laufzeiten, Übergänge sowie Schrifteinstellungen der Slideshow-Funktion (`memories.slideshow.*`).

  Die Übergänge aus `memories.slideshow.transitions` spiegeln die kuratierte Auswahl des `xfade`-Filters wider und enthalten
  standardmäßig `fade`, `dissolve`, `fadeblack`, `fadewhite`, `wipeleft`, `wiperight`, `wipeup`, `wipedown`, `slideleft`,
  `slideright`, `smoothleft`, `smoothright`, `circleopen`, `circleclose`, `radial`, `hlslice`, `vuslice`, `distance` und
  `pixelize`. Die Liste kann beliebig erweitert oder reduziert werden; der Slideshow-Manager trimmt Eingaben und reicht sie
  unverändert an FFmpeg weiter.

Die meisten Parameter besitzen einen `*_default`-Wert, der über eine gleichnamige Umgebungsvariable (z. B. `MEMORIES_HOME_RADIUS_KM`) übersteuert werden kann.

### Auswahlprofile (`memories.cluster.selection.profile_values`)

- Sämtliche Story-Profile (z. B. `vacation_weekend_transit`, `location`, `scene`, `device`, `this_day_month`, `people_friends`, `highlights`) definieren jetzt den kompletten Optionssatz, den `SelectionProfileProvider` erwartet – inklusive `min_spacing_seconds`, `phash_min_hamming`, `max_per_staypoint`, `video_bonus`, `face_bonus`, `selfie_penalty`, `quality_floor`, `minimum_total`, `enable_people_balance`, `people_balance_weight` und `repeat_penalty`.
- Das Standortprofil (`location`) kuratiert 36 Medien bei maximal acht Stücken pro Tag, nutzt weiterhin eine Zeitslot-Breite von vier Stunden, hält aber nur noch 75 Sekunden Mindestabstand zwischen Kuratierungen. Bonuspunkte bleiben bewusst moderat mit `video_bonus` = 0,28 und `face_bonus` = 0,22, damit Clips und Porträts leichter einfließen, ohne das Scoring zu dominieren.
- Werte lassen sich pro Umgebung über `config/parameters.local.yaml` oder eine eigene Parameter-Datei anpassen. Für die globalen Defaults bleiben die Umgebungsvariablen `MEMORIES_CLUSTER_SELECTION_TARGET_TOTAL`, `MEMORIES_CLUSTER_SELECTION_MAX_PER_DAY`, `MEMORIES_CLUSTER_SELECTION_TIME_SLOT_HOURS`, `MEMORIES_CLUSTER_SELECTION_MIN_SPACING_SECONDS`, `MEMORIES_CLUSTER_SELECTION_PHASH_MIN_HAMMING`, `MEMORIES_CLUSTER_SELECTION_MAX_PER_STAYPOINT`, `MEMORIES_CLUSTER_SELECTION_VIDEO_BONUS`, `MEMORIES_CLUSTER_SELECTION_FACE_BONUS`, `MEMORIES_CLUSTER_SELECTION_SELFIE_PENALTY` und `MEMORIES_CLUSTER_SELECTION_QUALITY_FLOOR` zuständig.
- Temporäre Anpassungen bei Clustering-Läufen erfolgen über die bekannten CLI-Optionen `--sel-target-total`, `--sel-max-per-day` und `--sel-min-spacing`, die alle Profile gleichzeitig beeinflussen.

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
- **Gesichtserkennung & Qualität** – Service-Bindungen für Backends und Aggregatoren.
- **Clusterer & Ranking** – Strategien, Prioritäten und Konsolidierungsregeln (`MagicSunday\Memories\Service\Clusterer\*`).
- **Feed-Services** – Builder, Personalisierungsprofile und HTTP-Controller (`MagicSunday\Memories\Service\Feed\*`, `FeedController`).
- **Slideshow & Thumbnail** – Pfade, Übergaben und Dienstschnittstellen.
- **Sonstige Bindungen** – Repository-Aliase, Event-Listener und Helfer (`Support`, `Repository`, `Http`).

Beim Hinzufügen neuer Services sollten die benötigten Parameter in `parameters.yaml` abgelegt und – falls konfigurierbar – über `%env()%` exponiert werden.

## `config/templates/titles.yaml`

Hinterlegt lokalisierte Titel- und Untertitelvorlagen für Cluster-Kategorien. Platzhalter wie `{{ date_range }}`, `{{ place }}` oder `{{ year }}` werden zur Laufzeit ersetzt. Die Datei ist nach Strategien gruppiert (z. B. `time_similarity`, `year_in_review`) und aktuell in Deutsch verfügbar. Weitere Sprachen können durch zusätzliche Wurzelknoten (z. B. `en:`) ergänzt werden.

## Ergänzende Hinweise

- Änderungen an Konfigurationswerten sollten im Commit erläutert und – falls sie das Verhalten nach außen beeinflussen – in der README dokumentiert werden.
- Nach Anpassungen empfiehlt es sich, den Cache unter `var/cache/` zu leeren, damit Symfony die Container-Definitionen neu generiert.
- Neue Umgebungsvariablen müssen in Deployment-Umgebungen gesetzt werden, bevor sie in Produktion genutzt werden.
