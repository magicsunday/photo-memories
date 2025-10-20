[![Latest version](https://img.shields.io/github/v/release/magicsunday/photo-memories?sort=semver)](https://github.com/magicsunday/photo-memories/releases/latest)
[![License](https://img.shields.io/github/license/magicsunday/photo-memories)](https://github.com/magicsunday/photo-memories/blob/main/LICENSE)
[![CI](https://github.com/magicsunday/photo-memories/actions/workflows/ci.yml/badge.svg)](https://github.com/magicsunday/photo-memories/actions/workflows/ci.yml)

# Photo Memories

Photo Memories ist eine modulare PHP-8.4-Anwendung, die große Foto- und Videoarchive analysiert, anreichert und als kuratierte "Für dich"-Erinnerungen bereitstellt. Herzstück ist eine Symfony-Console-Anwendung mit Doctrine ORM, die Metadaten extrahiert, Orte geokodiert, Cluster bildet und daraus einen Feed für eine schlanke Single-Page-App generiert.

## Highlights

- **Vollständige Metadaten-Pipeline** – EXIF/XMP-Extraktion, Apple-spezifische Heuristiken, Qualitäts- und Vision-Metriken sowie Burst-/LivePhoto-Erkennung sorgen für reichhaltige Medienobjekte.
- **Geocoding & Points of Interest** – Ein mehrstufiger Workflow kombiniert Nominatim- und Overpass-Anfragen, speichert Lokationen mitsamt POI-Labels und Übersetzungen und erlaubt fein justierbare Aktualisierungsläufe.
- **Intelligentes Clustering** – Über 30 Strategien gruppieren Medien zu Themen, Reisen, Personen oder Zeitreihen; Konsolidierung, Scoring und Limitierungen lassen sich zentral steuern.
- **Feed-Generierung & Export** – Persistierte Cluster werden gefiltert, gewichtet und als JSON-Feed ausgespielt; HTML-Exporte und Vorschauen helfen bei manuellen Reviews.
- **Media-Delivery** – Thumbnails, Slideshow-Videos und Originaldateien werden über eine minimalistische HTTP-Schicht samt SPA aus `public/app` bereitgestellt.

## Architekturüberblick

| Ebene | Beschreibung |
| --- | --- |
| **Bootstrap & Laufzeit** | `src/Memories.php` bootet den DI-Container, registriert CLI-Kommandos und sorgt für SymfonyStyle-Ausgaben. `Application` ergänzt Branding und Versionierung aus `version`. |
| **Dependency Injection** | `config/services.yaml` aktiviert Autowiring/-configuration für `MagicSunday\Memories\*`, konfiguriert Aliasse (z. B. Hashing, Clusterer) und bindet Parameter aus `config/parameters.yaml` ein. Doctrine wird über eine Factory instanziiert. |
| **Entitäten & Persistenz** | Doctrine-Entitäten wie `Media`, `Location` und `Cluster` speichern Pfade, Checksums, Qualitätsmetriken, Geodaten, Personenlisten und Index-Stati. Umfangreiche Indizes beschleunigen Suche, Geocoding und Feed-Abfragen. |
| **Services** | Spezialisierte Services kümmern sich um Ingestion (`Service/Indexing`), Metadaten (`Service/Metadata`), Hashing (`Service/Hash`), Geocoding (`Service/Geocoding`), Clustering (`Service/Clusterer`), Feed-Building (`Service/Feed`) und Medienausgabe (`Service/Thumbnail`, `Service/Slideshow`). |
| **Frontend** | Unter `public/app` liegt eine Vite-basierte SPA mit Playwright-Tests. `public/index.php` dient als einfacher HTTP-Entrypoint und liefert API und statische Assets aus. |

## Datenfluss & Workflows

### 1. Ingestion & Metadaten

- `memories:index` durchsucht Medienverzeichnisse, extrahiert Metadaten per Pipeline und persistiert die Ergebnisse; optional entstehen Thumbnails. Optionen wie `--thumbnails`, `--force` oder `--strict-mime` steuern das Verhalten.
- Die Pipeline kombiniert mehrere Extraktoren (EXIF, IPTC/XMP, Dateinamen, Apple-Heuristiken, Burst/Live-Linking) sowie Enricher (z. B. Geo- oder Kalenderfeatures). Reihenfolge und Prioritäten werden über Tags in `services.yaml` definiert.
- Qualitätsmetriken, Hashes und Feature-Versionen landen direkt auf der `media`-Entität und ermöglichen spätere Re-Indexierung sowie Fehlerdiagnose.

### 2. Geocoding

- `memories:geocode` orchestriert das Laden und Aktualisieren von Orten. Per Optionen lassen sich Läufe als Dry-Run ausführen, Städte filtern oder bestehende POI-Daten neu laden.
- Standardparameter wie Nominatim-Endpunkte, Abstände, Allowed-POIs oder bevorzugte Locale sind zentral in `config/parameters.yaml` hinterlegt und können über `.env` überschrieben werden.
- Ein `DefaultGeocodingWorkflow` verbindet Nominatim- und Overpass-Abfragen, normalisiert Ergebnisse (inkl. `name:*`-Varianten) und versieht Orte mit Kontextscoring.

### 3. Clustering & Scoring

- `memories:cluster` lädt Medien, erstellt Draft-Cluster über Strategien (z. B. Urlaub, Jahrestage, Porträts, Personengruppen) und konsolidiert sie inklusive Score-Heuristiken. Parameter wie Max-Mitglieder pro Cluster oder Prioritäten werden zentral gepflegt.
- Detailinformationen, welche Metadaten jede Strategie benötigt, enthält `docs/cluster-metadata.md` – hilfreich für Pipeline-Konfigurationen und Optimierungen.

### 4. Feed-Aufbau & Ausspielung

- Persistierte Cluster werden über `FeedBuilder` in `MemoryFeedItem`-Objekte transformiert. `memories:feed:preview` zeigt die daraus resultierende Konsolidierung im Terminal, nutzt dabei den staypoint-basierten Selektionspfad (`SelectionPolicyProvider` + Day-Summary-Pipeline) und respektiert alle zur Laufzeit gesetzten Policy-Overrides; `memories:feed:export-html` erzeugt statische HTML-Previews inklusive Thumbnails.
- Die HTTP-Schicht bietet `/api/feed` (JSON-Feed mit Filterparametern für Score, Strategie oder Datum), `/api/feed/{id}` (Detaildatensatz mit vollständiger Galerie und Metadaten), `/api/media/{id}/thumbnail` (Thumbnail-Auslieferung mit dynamischer Breite) und `/api/feed/{id}/video` für generierte Rückblick-Videos.
- Slideshow-Jobs werden asynchron über `slideshow:generate` abgearbeitet; Parameter wie Bilddauer, Übergänge, Zielverzeichnis, Schriftfamilie/-datei oder Pfade zu `ffmpeg`/`php` sind konfigurierbar. Übergangslisten werden pro Rückblick deterministisch gemischt, damit API-Storyboard und Videorendering dieselbe Abfolge nutzen.

## Installation & Vorbereitung

1. **Voraussetzungen**
   - PHP ≥ 8.4 mit Extensions `dom`, `exif`, `fileinfo`, `pdo`, `pdo_mysql`.
   - Composer für PHP-Abhängigkeiten, Node.js ≥ 18 für das Frontend, sowie eine unterstützte Datenbank (MySQL/MariaDB) für Doctrine.
2. **Backend-Abhängigkeiten installieren**
   ```bash
   composer install
   ```
3. **Frontend-Abhängigkeiten installieren**
   ```bash
   npm install
   ```
4. **Umgebung konfigurieren**
   - `.env` anlegen (siehe `.env.dist`, falls vorhanden) oder Environment-Variablen setzen. `EnvironmentBootstrap::boot()` sucht nacheinander im Arbeitsverzeichnis, in PHAR-Pfaden und im Repository-Root nach `.env`-Dateien und lädt `.env.local`-Varianten automatisch.
   - Neue Regler für Kontinuitäts- und Scoring-Verhalten besitzen Defaults in `config/packages/memories.yaml` (z. B. `memories.thresholds.time_gap_hours_default = 2.0`) und können über `.env` überschrieben werden. `.env.dist` dokumentiert empfohlene Bereiche; ohne ENV greift stets der YAML-Default, ENV-Werte ersetzen diesen zur Laufzeit.
   - Relevante Variablen im Überblick:
     - `MEMORIES_THRESHOLDS_TIME_GAP_HOURS` startet bei 2.0 h, trennt Storylines bei größeren Zeitlücken und sollte je nach Episodenlänge zwischen 1–12 h liegen.
     - `MEMORIES_THRESHOLDS_SPACE_GAP_METERS` beginnt bei 250 m, entscheidet über räumliche Trennung und bewegt sich typischerweise zwischen 150–500 m.
     - `MEMORIES_VACATION_MIN_DAYS` (Default 3 Tage) legt die minimale Urlaubsdauer fest; Werte zwischen 2–7 Tagen passen die Empfindlichkeit an.
     - `MEMORIES_DUPSTACK_HAMMING_MAX` (Start 9) steuert die pHash-Dublettenbildung und lässt sich für feinere bzw. lockerere Gruppen im Bereich 6–12 variieren.
    - `MEMORIES_SCORING_WEIGHTS_QUALITY`, `MEMORIES_SCORING_WEIGHTS_RELEVANCE`, `MEMORIES_SCORING_WEIGHTS_LIVELINESS` und `MEMORIES_SCORING_WEIGHTS_DIVERSITY` balancieren Qualitäts-, Kontext-, Bewegungs- und Diversitätseinflüsse (Defaults 0.22/0.45/0.08/0.25; empfohlen 0.2–0.3 / 0.4–0.6 / 0.05–0.15 / 0.2–0.3).
     - `MEMORIES_SLIDESHOW_DURATION_PER_IMAGE` (Default 3.5 s), `MEMORIES_SLIDESHOW_TRANSITION_DURATION` (0.75 s) und `MEMORIES_SLIDESHOW_ZOOM_MIN/MAX` (1.03/1.08) bestimmen Tempo und Ken-Burns-Zoombereich; empfehlenswerte Bereiche liegen bei 3–6 s, 0.5–1.5 s sowie 1.0–1.1 / 1.05–1.2.
   - Weitere Laufzeitvariablen wie `MEMORIES_MEDIA_DIR`, `DATABASE_URL`, `NOMINATIM_BASE_URL`, `MEMORIES_THUMBNAIL_DIR`, `FFMPEG_PATH` oder `MEMORIES_CLUSTER_MAX_MEMBERS` sollten wie gewohnt projekt- bzw. umgebungsspezifisch gesetzt werden.
   - Heimat-Referenz zwingend setzen: `MEMORIES_HOME_LAT`, `MEMORIES_HOME_LON` und `MEMORIES_HOME_RADIUS_KM` müssen gültige Werte tragen. Bleiben die Defaults `0/0` aktiv, warnt `memories:cluster` im Telemetrie-Block und die Debug-Ausgabe weist auf die Fehlkonfiguration hin.
5. **Datenbank vorbereiten**
   ```bash
   bin/console doctrine:database:create
   bin/console doctrine:migrations:migrate
   ```

## Typischer Arbeitsablauf

```bash
# Medien indexieren
php src/Memories.php memories:index /pfad/zur/mediathek --thumbnails

# Geodaten und POIs aktualisieren
php src/Memories.php memories:geocode --refresh-pois

# Cluster berechnen und speichern
php src/Memories.php memories:cluster --replace
# Debug-Infos für Urlaubssegmente anzeigen
php src/Memories.php memories:cluster --replace --debug-vacation

# Feed prüfen bzw. exportieren
php src/Memories.php memories:feed:preview --limit-clusters=2000
php src/Memories.php memories:feed:export-html var/export
```

Die gleiche Laufzeit dient auch als Einstiegspunkt für das Slideshow-Backend (`php src/Memories.php slideshow:generate <job.json>`).

## HTTP-Server & SPA

```bash
# PHP-HTTP-Einstieg samt API (Port 8080)
make web-serve

# Vite-Entwicklungsserver (Hot Module Replacement)
make web-dev
```

- Ohne gebaute Assets liefert `public/index.php` die Dev-Variante `public/app/index.html` aus, andernfalls Dateien aus `public/app/dist`. Die SPA nutzt `MEMORIES_HOME_VERSION_HASH`, um Client-Caches zu invalidieren.
- Produktiv-Build und Preview werden über `make web-build` bzw. `make web-preview` ausgelöst; Playwright-Tests laufen mit `make web-test`.

## Konfiguration & Tuning

- **Indexierung**: Dateiendungen, Batch-Größe, Hash-Parameter und die (standardmäßig aktive) EXIF-Thumbnail-Ausrichtung sind in `config/parameters.yaml` definiert und via `.env` überschreibbar.
- **Geocoding**: Zeitversatz, POI-Radius, erlaubte Kategorien und bevorzugte Locale lassen sich zentral konfigurieren.
- **Cluster & Feed**: Konsolidierungsregeln, Gruppen, Prioritäten sowie Limits pro Strategie stehen in `config/parameters.yaml`. Anpassungen wirken sich unmittelbar auf Konsolidierung und Feed-Ranking aus.
- **Video/Thumbnails**: Pfade zu `ffmpeg`/`ffprobe`, Ausgabeverzeichnisse, Bildgrößen und Orientierungsverhalten sind parametrisiert.

### Schwellen & Scores feinjustieren

- Passe `MEMORIES_THRESHOLDS_TIME_GAP_HOURS` und `MEMORIES_THRESHOLDS_SPACE_GAP_METERS` an, wenn Storylines zu häufig getrennt bzw. zusammengefasst werden – kleinere Werte verdichten Tagebücher, größere lassen Reisecluster länger bestehen.
- `MEMORIES_VACATION_MIN_DAYS` und `MEMORIES_DUPSTACK_HAMMING_MAX` helfen, Urlaubserkennung und Dublettenbildung an Datenlage und Gerätevielfalt anzunähern.
- Bei veränderten Qualitätsanforderungen oder lebhafteren Feeds lohnt ein Feintuning der Gewichte `MEMORIES_SCORING_WEIGHTS_*`; ziehe dabei die Default-Matrix aus `config/packages/memories.yaml` und die Richtwerte in `.env.dist` heran, um Balanceverschiebungen gezielt zu testen.
- Für langsamere/kompaktere Slideshows `MEMORIES_SLIDESHOW_DURATION_PER_IMAGE`, `MEMORIES_SLIDESHOW_TRANSITION_DURATION` und `MEMORIES_SLIDESHOW_ZOOM_MIN/MAX` im Einklang mit Teampräferenzen und Playerlaufzeiten variieren.

### Kuratierungsprofile anpassen

- Die Story-Profile unter `memories.cluster.selection.profile_values` definieren vollständige Optionssätze (`min_spacing_seconds`, `phash_min_hamming`, `max_per_staypoint`, `video_bonus`, `face_bonus`, `selfie_penalty`, `quality_floor`, `minimum_total` usw.). Die Default-Werte folgen den Curation-Briefs für Urlaub, Orte, Szenen, Geräte, „An diesem Tag/Monat“, Freund:innen und Highlights.
- Individuelle Installationen können diese Werte über `config/parameters.local.yaml` oder deploymentspezifische Overrides anpassen. Für globale Defaults stehen weiterhin die Umgebungsvariablen `MEMORIES_CLUSTER_SELECTION_TARGET_TOTAL`, `MEMORIES_CLUSTER_SELECTION_MAX_PER_DAY`, `MEMORIES_CLUSTER_SELECTION_TIME_SLOT_HOURS`, `MEMORIES_CLUSTER_SELECTION_MIN_SPACING_SECONDS`, `MEMORIES_CLUSTER_SELECTION_PHASH_MIN_HAMMING`, `MEMORIES_CLUSTER_SELECTION_MAX_PER_STAYPOINT`, `MEMORIES_CLUSTER_SELECTION_VIDEO_BONUS`, `MEMORIES_CLUSTER_SELECTION_FACE_BONUS`, `MEMORIES_CLUSTER_SELECTION_SELFIE_PENALTY` und `MEMORIES_CLUSTER_SELECTION_QUALITY_FLOOR` zur Verfügung.
- Temporäre Laufzeit-Overrides lassen sich über `memories:cluster --sel-target-total=… --sel-max-per-day=… --sel-min-spacing=… --sel-phash-hamming=… --sel-max-staypoint=…` setzen; die Optionen wirken auf sämtliche Profile während dieses Jobs.
- Mit `--debug-vacation` zeigt der CLI-Lauf vor dem Persistieren eine tabellarische Übersicht der erkannten Urlaubssegmente sowie die fünf bestbewerteten Cluster an.

#### Filteroption `--types`

`memories:curate --types` akzeptiert weiterhin die internen Konsolidierungsgruppen (`travel_and_places`, `people_and_moments` usw.), kann aber nun auch mit Friendly Names aufgerufen werden. Die Aliasse werden im Container hinterlegt (`memories.cluster.consolidate.group_aliases`) und auf dieselben Gruppen gemappt:

- `travel_and_places` – Friendly Names: `vacation`, `travel`, `trip`, `places`, `sightseeing`
- `people_and_moments` – Friendly Names: `people`, `friends`, `family`
- `city_and_events` – Friendly Names: `events`, `city`, `nightlife`
- `time_and_basics` – Friendly Names: `onthistoday`, `onthisday`, `monthly`, `basics`
- `nature_and_seasons` – Friendly Names: `seasons`, `nature`
- `home` – Friendly Names: `home`, `staycation`
- `motifs_and_formats` – Friendly Names: `motifs`, `formats`
- `similarity_algorithms` – Friendly Names: `similarity`, `duplicates`

Mehrere Werte können wie bisher kommasepariert oder als wiederholte `--types`-Optionen gesetzt werden; die Eingaben werden fallunabhängig ausgewertet.

## Tests & Qualitätssicherung

- Komplettes CI-Profil: `composer ci:test` (Linting, PHPStan, Rector/Fractor Dry-Run, Coding-Guidelines, PHPUnit).
- Einzelne Schritte: `composer ci:test:php:lint`, `composer ci:test:php:phpstan`, `composer ci:test:php:unit` usw.
- Frontend-E2E: `npm run test:e2e` bzw. `make web-test` startet Playwright.

### Erinnerungs-Fixtures & Cluster-Integration

- Unter `fixtures/memories/<dataset>/` liegen kuratierte Datensätze inklusive `metadata.json`, SVG-Vorschaubildern (`*.svg`, viewBox 64×64)
  und einem YAML-Goldstandard (`expected.yaml`). Die Szenarien decken Wochenend-Kurztrips, Familienfeiern und Monatsmixe mit
  zeitlichen Lücken ab.
- `test/Integration/Clusterer/MemoryDatasetClusterPipelineTest.php` lädt die Metadaten, führt die Test-Pipeline
  (`MemoryDatasetPipeline`) durch und vergleicht die Ausgabe mit dem Goldstandard. Der Test läuft automatisch mit
  `composer ci:test:php:unit`.
- Neue Szenarien lassen sich anlegen, indem ein zusätzlicher Ordner erzeugt, die Metadaten ergänzt und die Erwartungsdatei
  via `php <<'PHP' …` (siehe [Testleitfaden](docs/testing-fixtures.md)) oder manuell aus den Pipeline-Ergebnissen aktualisiert wird. Achte darauf,
  die Vorschaubilder klein zu halten (≤64×64 px) und `expected.yaml` nach Anpassungen im Repository zu versionieren.

## Build & Releases

- `make init` richtet die Build-Umgebung ein, `make build` erstellt das distributable Binary über `.build/build`. Release-Versionen werden mittels `make version` bzw. `scripts/create-version` vorbereitet.
- Das Binary enthält denselben Console-Kernel wie die Entwicklungsvariante, inkl. Container-Cache unter `var/cache`.

## Repository-Layout

| Pfad | Inhalt |
| --- | --- |
| `src/` | PHP-Produktionscode (Commands, Services, Entities, HTTP, Feed, Clusterer, Utilities). |
| `config/` | Symfony-Services, Parameter, Umgebungswerte. |
| `public/` | HTTP-Einstiegspunkt, SPA-Quellen, ausgelieferte Videos. |
| `docs/` | Vertiefende Dokumentation (Cluster-Strategien, Integrationsnotizen, Testergebnisse). |
| `test/` | PHPUnit-Tests unter `MagicSunday\Memories\Test`. |
| `.build/`, `Make/`, `scripts/` | Build- und CI-Werkzeuge, Hilfsskripte, QA-Konfiguration. |

## Weiterführende Dokumente

- [`docs/cluster-metadata.md`](docs/cluster-metadata.md) – Detailanforderungen aller Cluster-Strategien.
- [`docs/cluster-metadata-integration.md`](docs/cluster-metadata-integration.md) – Analyse der aktuellen Metadaten-Nutzung und Optimierungsmaßnahmen.
- [`docs/test-report.md`](docs/test-report.md) – Historische Testergebnisse und QA-Protokolle.

---

> 💡 Tipp: Nach Änderungen am DI-Setup ggf. `var/cache/DependencyContainer.php` löschen, falls der Container veraltete Definitionen enthält.
