[![Latest version](https://img.shields.io/github/v/release/magicsunday/photo-memories?sort=semver)](https://github.com/magicsunday/photo-memories/releases/latest)
[![License](https://img.shields.io/github/license/magicsunday/photo-memories)](https://github.com/magicsunday/photo-memories/blob/main/LICENSE)
[![CI](https://github.com/magicsunday/photo-memories/actions/workflows/ci.yml/badge.svg)](https://github.com/magicsunday/photo-memories/actions/workflows/ci.yml)

# Photo Memories

## Repository Layout

- `src/` – Produktionscode und alle Cluster-Strategien.
- `config/` – Symfony-DI-Konfiguration, Parameter und Index-Pipeline.
- `docs/cluster-metadata.md` – Übersicht der Cluster-Strategien mit ihren Metadaten-Abhängigkeiten.

## HTTP-Feed & Single-Page-App

Der neue HTTP-Einstiegspunkt unter `public/index.php` liefert zwei Dinge:

* `GET /api/feed` – JSON-Ausgabe des Feed-Builders (Filter für Score, Strategie, Datum)
* `GET /api/media/{id}/thumbnail` – Auslieferung der generierten Thumbnails bzw. Originaldateien
* `GET /api/feed/{id}/video` – Auslieferung der erzeugten Rückblick-Videos

Unter `public/app/` liegt eine schlanke SPA (Vanilla JS + Vite), die den Feed lädt, Filter per UI anbietet und Cover-Galerien animiert darstellt. Die Anwendung läuft sowohl gegen den lokalen PHP-Server als auch gegen den Vite-Entwicklungsserver.

> **Hinweis:** Die SPA respektiert die Umgebungsvariable `MEMORIES_HOME_VERSION_HASH`, um clientseitige Caches zu steuern. Wird sie nicht gesetzt, greift automatisch der Fallback-Wert `home_config_v1` aus `config/parameters.yaml`.

### Rückblick-Videos

Jeder Rückblick erhält optional ein automatisch generiertes Video, das die Vorschaubilder in einer Sequenz mit Überblendungen zeigt. Die Generierung erfolgt asynchron über den neuen Konsolenbefehl `slideshow:generate`, der intern durch den HTTP-Controller angestoßen wird. Bereits erzeugte Videos landen standardmäßig im Verzeichnis `public/videos/` (konfigurierbar über die Umgebungsvariable `MEMORIES_SLIDESHOW_DIR`) und werden beim nächsten Abruf wiederverwendet, sodass keine unnötige Rechenzeit entsteht.

Die Laufzeit pro Bild sowie die Dauer der Übergänge lassen sich über die Parameter `memories.slideshow.image_duration_s` und `memories.slideshow.transition_duration_s` in `config/parameters.yaml` anpassen. Für andere Ausgabegrößen gibt es zusätzlich `memories.slideshow.video_width` und `memories.slideshow.video_height`. Standardmäßig verwendet der Generator das `ffmpeg`-Binary aus dem `PATH`. Falls es an einem anderen Ort liegt, kann der Pfad über den Parameter `memories.slideshow.ffmpeg_path` (oder die Umgebungsvariable `FFMPEG_PATH`) überschrieben werden. Der PHP-Hintergrundprozess nutzt automatisch das vom [PhpExecutableFinder](https://symfony.com/doc/current/components/process.html#locating-the-php-binary) ermittelte CLI-Binary. Installationen, bei denen dieses nicht gefunden wird, können über den Parameter `memories.slideshow.php_binary` bzw. die neue Umgebungsvariable `MEMORIES_PHP_BINARY` explizit einen Pfad angeben.

### Schnelleinstieg

```bash
# Abhängigkeiten installieren
make web-install

# PHP-Einstieg starten (separates Terminal)
make web-serve

# Vite-Entwicklungsserver starten (separates Terminal)
make web-dev

# Build-Artefakte erzeugen
make web-build

# Gebautes Bundle lokal prüfen
make web-preview

# Playwright-Browsertests ausführen
make web-test
```

Nach dem Build liefert `public/index.php` automatisch die Dateien aus `public/app/dist/` aus. Ohne Build fällt der Fallback auf `public/app/index.html` zurück (z. B. für reine SPA-Prototypen).

## Localised Points of Interest

Photo Memories enriches locations with nearby points of interest fetched from the OpenStreetMap Overpass API. These POIs now
capture all available `name:*` variants plus optional `alt_name` entries. The application stores them in a dedicated `names`
structure alongside the legacy `name` field so consumers can choose the most appropriate label for their locale.

By default the Overpass enrichment focuses on sightseeing-related categories to reduce noise. Each query block is described by a
combination of tags that must match together. The bundled combinations are:

| Combination |
|-------------|
| `tourism` in {`attraction`, `viewpoint`, `museum`, `gallery`} |
| `historic` in {`monument`, `castle`, `memorial`} |
| `man_made` in {`tower`, `lighthouse`} |
| `leisure` in {`park`, `garden`} |
| `natural` in {`peak`, `cliff`} |

You can extend this list without touching the code by overriding the Symfony parameter
`memories.geocoding.overpass.allowed_pois` (e.g. in `config/parameters.local.yaml` or environment specific configuration).
Provide it as a list of combinations, where each entry defines the required tags for one `nwr` block:

```yaml
memories.geocoding.overpass.allowed_pois:
  -
    tourism: [ 'attraction' ]
    historic: [ 'castle', 'ruins' ]
  -
    tourism: [ 'theme_park' ]
```

Entries are merged with the defaults so new keys or values become part of both the Overpass query and the tag validation pipeline.

To control which language is preferred when rendering titles or cluster labels, configure the new
`MEMORIES_PREFERRED_LOCALE` environment variable (or its matching Symfony container parameter
`memories.localization.preferred_locale`). When set, `LocationHelper::displayLabel()` and related helpers first attempt to use
the matching `name:<locale>` value before falling back to the default name, other available translations, or alternative
labels.

Example `.env.local` snippet:

```dotenv
MEMORIES_PREFERRED_LOCALE=de
```

Leave the variable unset to retain the previous behaviour of using the generic `name` tag provided by Overpass.

## Thumbnail-Ausrichtung

Die Thumbnail-Pipeline ignoriert EXIF-Orientierungsflags jetzt standardmäßig und erzeugt die verkleinerten JPEGs genau so, wie
die Pixeldaten auf der Platte liegen. Falls du weiterhin automatische Rotationen anhand des Flags benötigst, setzt du in deiner
`.env` den Schalter `MEMORIES_THUMBNAIL_APPLY_ORIENTATION=1`. Mit dem Standardwert `0` bleibt das frühere Verhalten deaktiviert,
was insbesondere für bereits physisch gedrehte Bilder mit inkonsistentem Orientation-Tag Fehler vermeidet.

## Index-Metadaten & Fehlerdiagnose

Die Ingestion-Pipeline erweitert jeden `media`-Datensatz jetzt um strukturierte Index-Metadaten:

* `feature_version` (`INT`) speichert die Versionsnummer der Metadaten-Extraktion. Die CLI gibt diese Zahl beim Start von `memories:index` aus, sodass du sofort siehst, welche Feature-Revision aktiv ist.
* `indexed_at` (`DATETIME`) hält fest, wann die letzte Extraktion abgeschlossen wurde.
* `index_log` (`TEXT`) enthält eine detaillierte Fehlermeldung, falls ein Extraktor eine Exception wirft. Erfolgreiche Durchläufe leeren das Feld automatisch.
* `needs_rotation` (`BOOL`) markiert Medien, bei denen Clients weiterhin eine Drehung anhand der EXIF-Orientation anwenden müssen.

Damit lassen sich fehlgeschlagene Läufe schneller erkennen und neu anstoßen, ohne auf externe Logs angewiesen zu sein. Nach einem `memories:index`-Lauf prüfst du die Spalten direkt in der Datenbank oder über deine Auswertungen; das Flag `needs_rotation` hilft beim gezielten Nachbearbeiten von Assets mit reiner Orientation-Markierung.

## Konfigurierbare Batch-Größe beim Persistieren

Die Persistence-Stage bündelt die Schreibvorgänge gegen die Datenbank ab sofort über den Parameter `memories.index.batch_size`. Der Standardwert `500` liegt in `config/parameters.yaml` und lässt sich bei Bedarf in deiner `config/services.yaml` bzw. per Umgebungsvariable (`MEMORIES_INDEX_BATCH_SIZE`) überschreiben. Erst wenn so viele Medien persistiert wurden, stoßen `flush()` und `clear()` einen neuen Commit-Zyklus an; mit kleineren Werten behältst du bei speicherarmen Umgebungen die Kontrolle, größere Werte reduzieren den Datenbank-Overhead bei Masseningestion.

## Datenbankindizes

Damit Geocoding, Duplikatsuche und Video-Feeds auch bei großen Beständen performant bleiben, lohnt sich ein kurzer Blick auf die empfohlenen Indizes der Tabelle `media`:

* `idx_media_geocell8` beschleunigt das Laden von GPS-Datensätzen nach Geohash-Zellen – die Geocoding-Workflows sortieren nun zuerst nach `geoCell8` und anschließend nach Aufnahmedatum.
* `idx_media_phash_prefix` reduziert pHash-Suchen auf die relevanten Präfix-Buckets, bevor die Hamming-Distanz berechnet wird.
* `idx_media_burst_taken` unterstützt Auswertungen innerhalb eines Burst-Stapels (z. B. wenn mehrere Serienaufnahmen synchronisiert werden sollen).
* `idx_media_video_taken` hilft insbesondere bei videozentrierten Feeds: Repository-Abfragen filtern Videos explizit und lesen sie chronologisch nach `takenAt` ein.
* `idx_media_location` deckt alle Abfragen ab, die nach vorhandenen Ortszuweisungen filtern (z. B. für POI-Aktualisierungen).

Zusätzlich empfiehlt sich bei größeren Installationen ein kombinierter Index auf `noShow, lowQuality`. Damit beantwortet die Qualitätspipeline Rückfragen nach „versteckten“ oder minderwertigen Medien deutlich schneller, ohne die Feed-Generierung auszubremsen:

```sql
CREATE INDEX idx_media_noshow_lowquality ON media (noShow, lowQuality);
```

Passe die Empfehlung je nach Datenbankdialekt an (z. B. `CREATE INDEX` vs. `CREATE INDEX IF NOT EXISTS`).

> **Hinweis:** Nach dem Update auf diese Version führst du einmal `bin/console doctrine:migrations:migrate` aus, damit alle neuen `media`-Indizes sowie die geänderte Spaltenlänge von `phashPrefix` in der Datenbank landen.

## Cluster-Konfiguration

Die Persistierung der berechneten Cluster wird jetzt begrenzt, damit Feeds und Oberflächen nicht mit hunderten Medien pro Block
überladen werden. Über die neue Umgebungsvariable `MEMORIES_CLUSTER_MAX_MEMBERS` (Standard: `20`) legst du fest, wie viele
Medien pro Cluster maximal gespeichert werden. Für eine abweichende Konfiguration kannst du den Wert entweder direkt in deiner
`.env` oder über einen passenden Symfony-Parameter überschreiben (`memories.cluster.persistence.max_members`).
Die Auswahl innerhalb dieses Limits wird nun vorab nach einem qualitätsbasierten Score sortiert: Auflösungs- und Schärfedaten, ISO-Normalisierung sowie die ästhetischen Kenngrößen aus den Medien fließen gemeinsam mit den von der `QualityClusterScoreHeuristic` berechneten Aggregaten in die Bewertung ein. Wiederholte Aufnahmen mit identischem `phash`, `dhash` oder identischer `burstUuid` werden pro Treffer stärker abgewertet, sodass die auf %memories.cluster.persistence.max_members% (= `MEMORIES_CLUSTER_MAX_MEMBERS`) begrenzte Top-Auswahl bevorzugt einzigartige Motive enthält.
Für die Home-Erkennung sind zwei weitere Parameter relevant: `memories.home.max_centers` begrenzt die Anzahl der priorisierten Heimat-Zentren (Standard: 2), und `memories.home.fallback_radius_scale` erweitert bei dichten, aber bewegungsarmen Aufenthalten den aktiven Radius. Dadurch bewertet die Away-Logik mehrere Lebensmittelpunkte konsistent als „zu Hause“, ohne tageslange Innenstadtaufenthalte fälschlich als Reise zu markieren.
Bei Urlaubsclustern wird die Reihenfolge zusätzlich über Tages-Slots ausbalanciert. Pro Abschnitt wandert das bestbewertete Foto nach vorn, bevor die restlichen Kandidaten folgen. Dadurch bleiben nach dem Clamping weiterhin alle Reisetage im Feed, und die Cover-Auswahl in Vorschau sowie Export spiegelt die zeitliche Dramaturgie der Reise wider.

### Metadaten-Schutz beim Clustern

Vor jedem Clustering-Lauf prüft `memories:cluster`, ob alle geladenen Medien mit der aktuellen `MetadataFeatureVersion::CURRENT` indiziert wurden. Finden sich abweichende Versionen, informiert die CLI mit einer Warnung und bricht bei gesetztem `--replace` sofort ab, damit keine Mischstände persistiert werden. Starte in diesem Fall zunächst `memories:index` (ggf. ebenfalls mit `--replace`), um die Metadaten auf den neuesten Stand zu bringen und das Clustering anschließend erneut auszuführen.

### Qualitätsaggregation

Die Vision-Pipeline speichert jetzt voraggregierte Qualitätsmetriken direkt am `media`-Datensatz. Aus Auflösung, Schärfe, ISO-Normalisierung sowie den Helligkeits- und Kontrastmessungen entstehen die drei neuen Felder `quality_score`, `quality_exposure` und `quality_noise` (alle `FLOAT`). Zusätzlich markiert das Flag `low_quality` (`BOOL`) problematische Aufnahmen. Ein Bild gilt als niedrigwertig, sobald einer der folgenden Werte unterschritten wird:

* Gesamtqualität `< 0.35`
* Effektive Auflösung `< 0.30`
* Schärfe `< 0.30`
* Belichtungs-Score `< 0.25`
* Rausch-Score `< 0.25`

Alle Score-Werte bewegen sich zwischen `0` und `1`, wobei `1` den bestmöglichen Zustand beschreibt. Die Cluster-Heuristiken greifen bevorzugt auf diese aggregierten Zahlen zu; fehlen sie, bleiben die Rohmetriken (Auflösung, Schärfe, ISO, Helligkeit usw.) als Fallback erhalten.

Für die Berechnung der Qualitätsmetriken und Posterframes greift der Indexer standardmäßig auf die Binaries `ffmpeg` und `ffprobe` aus dem `PATH` zu. Solltest du abweichende Installationspfade verwenden, setzt du die Umgebungsvariablen `FFMPEG_PATH` bzw. `FFPROBE_PATH` oder überschreibst die zugehörigen Symfony-Parameter `memories.video.ffmpeg_path` und `memories.video.ffprobe_path` in deiner Konfiguration. Beide Parameter bringen nun ohne weitere Anpassungen lauffähige Standardwerte mit.

## Statische Analyse & ungenutzte Variablen

PHPStan meldet Variablen automatisch als ungenutzt, wenn ihr Inhalt im weiteren Kontrollfluss keine Rolle spielt. Das schließt zwei typische Situationen ein:

* Der zugewiesene Wert taucht nirgendwo mehr auf oder wird direkt wieder überschrieben.
* Eine per Referenz gespeicherte Variable wird nirgends gelesen oder gleich im Anschluss über eine neue Referenz ersetzt.

Sorge in diesen Fällen für einen gezielten Einsatz oder entferne die unnötigen Zuweisungen, damit der Analyse-Lauf sauber durchläuft.
