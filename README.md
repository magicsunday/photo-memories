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

- Persistierte Cluster werden über `FeedBuilder` in `MemoryFeedItem`-Objekte transformiert. `memories:feed:preview` zeigt die daraus resultierende Konsolidierung im Terminal; `memories:feed:export-html` erzeugt statische HTML-Previews inklusive Thumbnails.
- Die HTTP-Schicht bietet `/api/feed` (JSON-Feed mit Filterparametern für Score, Strategie oder Datum), `/api/media/{id}/thumbnail` (Thumbnail-Auslieferung mit dynamischer Breite) und `/api/feed/{id}/video` für generierte Rückblick-Videos.
- Slideshow-Jobs werden asynchron über `slideshow:generate` abgearbeitet; Parameter wie Bilddauer, Übergänge, Zielverzeichnis oder Pfade zu `ffmpeg`/`php` sind konfigurierbar.

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
   - Wichtige Variablen: `MEMORIES_MEDIA_DIR`, `DATABASE_URL`, `NOMINATIM_BASE_URL`, `NOMINATIM_EMAIL`, `MEMORIES_HOME_LAT/LON`, `MEMORIES_THUMBNAIL_DIR`, `FFMPEG_PATH`, `FFPROBE_PATH`, `MEMORIES_CLUSTER_MAX_MEMBERS`.
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

- **Indexierung**: Dateiendungen, Batch-Größe, Hash-Parameter und Thumbnail-Ausrichtung sind in `config/parameters.yaml` definiert und via `.env` überschreibbar.
- **Geocoding**: Zeitversatz, POI-Radius, erlaubte Kategorien und bevorzugte Locale lassen sich zentral konfigurieren.
- **Cluster & Feed**: Konsolidierungsregeln, Gruppen, Prioritäten sowie Limits pro Strategie stehen in `config/parameters.yaml`. Anpassungen wirken sich unmittelbar auf Konsolidierung und Feed-Ranking aus.
- **Video/Thumbnails**: Pfade zu `ffmpeg`/`ffprobe`, Ausgabeverzeichnisse, Bildgrößen und Orientierungsverhalten sind parametrisiert.

## Tests & Qualitätssicherung

- Komplettes CI-Profil: `composer ci:test` (Linting, PHPStan, Rector/Fractor Dry-Run, Coding-Guidelines, PHPUnit).
- Einzelne Schritte: `composer ci:test:php:lint`, `composer ci:test:php:phpstan`, `composer ci:test:php:unit` usw.
- Frontend-E2E: `npm run test:e2e` bzw. `make web-test` startet Playwright.

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
