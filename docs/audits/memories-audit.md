# Memories Pipeline Audit (2025-10-18)

## Summary

| Baustein | Status | Hinweise |
| --- | --- | --- |
| Cluster-Strategien & Hybrid-Pipeline | gedeckt | `HybridClusterer` orchestriert alle getaggten Strategien und versieht Entwürfe nach dem Score-Lauf mit Titeln; `config/services.yaml` deklariert Zeit-, Orts-, Personen- und Event-Heuristiken inklusive Quality-Aggregation und Video-sensitiver Parameterisierung.【F:src/Service/Clusterer/HybridClusterer.php†L34-L174】【F:config/services.yaml†L535-L708】 |
| Kuration & Selektions-Layer | teilweise | Es existiert kein eigenständiger Namespace `MagicSunday\\Memories\\Curator`; Selektionsprofile, Laufzeit-Overrides und Hard/Soft-Stages liegen unter `Service\\Clusterer\\Selection` und werden via YAML-Profilen gesteuert.【F:src/Service/Clusterer/Selection/SelectionPolicyProvider.php†L32-L199】【F:config/services.yaml†L1110-L1158】【F:config/parameters.yaml†L340-L436】 |
| Indexierung & Signalextraktion | gedeckt | Die Default-Media-Ingestion-Pipeline sequentiert MIME-Check, Zeit-/Geo-Normalisierung, Qualitäts- und Hash-Stufen, QA-Logging und Persistenz; `memories:index` bietet Force/Dry-Run/Video/Thumbnail-Optionen sowie QA-Report-Ausgabe.【F:src/Service/Indexing/DefaultMediaIngestionPipeline.php†L24-L108】【F:config/services.yaml†L390-L407】【F:src/Command/IndexCommand.php†L35-L155】 |
| Feed Preview & HTML-Export | gedeckt | `memories:feed:preview` erlaubt Score-, Member- und Per-Media-Cap-Overrides inklusive Konsolidierung; `memories:feed:export-html` generiert statische Feeds mit Limit-/Thumbnail-Steuerung und Symlink-Modus.【F:src/Command/FeedPreviewCommand.php†L44-L200】【F:src/Command/FeedExportHtmlCommand.php†L36-L112】 |
| `memories:curate-vacation` | fehlt | Im Command-Verzeichnis liegt kein entsprechender Befehl; Vacation-Kuration erfolgt ausschließlich im Cluster-/Selektionslauf.【09a054†L1-L2】 |
| Strategische Gewichtung & Prioritäten | gedeckt | YAML-Parameter definieren Konsolidierungs-Schwellen, Keep-Order-Gruppen sowie Prioritäten und Boosts pro Strategie/Algorithmus.【F:config/parameters.yaml†L470-L578】【F:config/parameters.yaml†L728-L798】 |
| Feature-Flags & Runtime-Schalter | teilweise | Zahlreiche `%env()%`-basierte Schalter (Indexing-, Video-, Face-Detection-, Telemetrie- und Slideshow-Settings) existieren, jedoch ohne zentrale Flag-Registry oder vereinheitlichte Namenskonvention.【F:config/parameters.yaml†L14-L55】【F:config/services.yaml†L560-L569】 |
| Datenmodell (Cluster/Media/Memory/Location) | teilweise | Doctrine-Entities modellieren Cluster, Medien, Duplikate und Locations mit Indizes/FKs; Cluster-Mitglieder verbleiben jedoch als JSON-Feld ohne relationale Auflösung, Materialized Views fehlen.【F:src/Entity/Cluster.php†L29-L191】【F:src/Entity/Media.php†L25-L156】【F:src/Entity/MediaDuplicate.php†L22-L142】【F:src/Entity/Location.php†L21-L176】 |
| Qualitäts-, Ästhetik- & Duplikat-Heuristiken | gedeckt | Qualitätsscores berücksichtigen Schärfe, Belichtung, ISO, Clipping und Noise-Dekay; Similarity-Metriken kombinieren Zeit/GPS/pHash/Personen; pHash/Ahash/Dhash-Extraktion plus Near-Duplicate-Stage persistiert Hamming-Distanzen.【F:src/Service/Metadata/Quality/MediaQualityAggregator.php†L24-L107】【F:src/Clusterer/Selection/SimilarityMetrics.php†L31-L149】【F:src/Service/Metadata/PerceptualHashExtractor.php†L47-L176】【F:src/Service/Indexing/Stage/NearDuplicateStage.php†L1-L64】 |
| Slideshow-Pipeline | gedeckt | `slideshow:generate` triggert den FFmpeg-basierten `SlideshowVideoGenerator` mit Ken-Burns, Blur/Vignette, Transition-Whitelist und Audio-Normalisierung; Parameterdatei steuert Pfade, Filter, Transitionen, Musik und Loudness.【F:src/Command/SlideshowGenerateCommand.php†L36-L106】【F:src/Service/Slideshow/SlideshowVideoGenerator.php†L77-L239】【F:config/parameters.yaml†L930-L956】 |

## Namespaces & Pipelines
- `MagicSunday\Memories\Clusterer` und `Service\Clusterer` kapseln Strategien, Quality-Aggregation, Scoring und Titelerzeugung, wobei `HybridClusterer` alle Strategien durchläuft und Titel nach Score-Phase vergibt.【F:src/Service/Clusterer/HybridClusterer.php†L34-L174】【F:config/services.yaml†L535-L708】
- Kuratierende Logik (Policy-Profile, Hard/Soft-Stages, Overrides) lebt unter `Service\Clusterer\Selection`; dedizierte `Curator`-Namespaces fehlen, die Funktionalität ist jedoch vollständig abgebildet.【F:src/Service/Clusterer/Selection/SelectionPolicyProvider.php†L32-L199】【F:config/services.yaml†L1110-L1158】
- Indexierung erfolgt unter `Service\Indexing` mit einer fest verdrahteten Stage-Pipeline inklusive MIME-, Zeit-, Geo-, Qualitäts-, Hash- und Persistenzschritten sowie Burst/Live-, Face- und Scene-Heuristiken.【F:config/services.yaml†L390-L407】
- Slideshow-Funktionalität ist im Namespace `Service\Slideshow` angesiedelt; ein separater Root-Namespace `MagicSunday\Memories\Slideshow` ist nicht erforderlich, da Videoerzeugung, Status und Jobs hier gebündelt sind.【F:src/Service/Slideshow/SlideshowVideoGenerator.php†L77-L239】

## CLI-Kommandos & Ablaufsteuerung
- `memories:index` orchestriert Locator, Pipeline, QA-Reporting sowie Force/Dry-Run/Video/Thumbnail-Optionen und zeigt Feature-Version sowie Fortschritt an.【F:src/Command/IndexCommand.php†L35-L155】
- `memories:cluster` (nicht im Detail auditiert) interagiert mit Selection-Overrides; `memories:feed:preview` und `memories:feed:export-html` decken Konsolidierung, Personalisierung und statische Exporte ab.【F:src/Command/FeedPreviewCommand.php†L44-L200】【F:src/Command/FeedExportHtmlCommand.php†L36-L112】
- Ein dediziertes `memories:curate-vacation`-Kommando existiert nicht; Vacation-Kuration findet über Cluster-Strategien und Selection-Profile statt.【09a054†L1-L2】

## Strategien, Scoring & Konsolidierung
- Strategien decken Zeit-/Orts-Nähe, Geräte, pHash, Burst, Panorama, Porträt, Video, Home, Anniversary, Personen, Feiertage, Nightlife sowie Travel/Vacation-Algorithmen ab, alle via `memories.cluster_strategy` getaggt.【F:config/services.yaml†L535-L708】
- Konsolidierungs-Pipeline umfasst Filter-Normalisierung, Qualitäts-Ranking, Member-Curation, Duplicate-Collapse, Nesting, Dominanz/Overlap-Handling, Annotation-Pruning, Per-Media-Cap und Titelkanonisierung.【F:config/services.yaml†L1022-L1108】
- Scoring-Heuristiken kombinieren Temporal-, Qualitäts-, People-, Content-, Location-, POI-, Novelty-, Holiday-, Recency- und Density-Scores mit gewichteten Boosts pro Algorithmus.【F:config/services.yaml†L1210-L1254】【F:config/parameters.yaml†L728-L798】
- YAML-Parameter definieren Konsolidierungs-Schwellen, Keep-Order-Listen, Gruppen, Annotate-only-Typen und Min-Unique-Shares zur Steuerung der Konsolidierungslogik.【F:config/parameters.yaml†L470-L578】

## Konfiguration & Feature-Flags
- `%env()%`-Overrides steuern Index-Batches, Video-Posterframes, FFmpeg/FFprobe-Pfade, Face-Detection-Binaries, Hash-Längen, Geocoding-Limits und Telemetrie-Schalter für Metadaten-Pipelines.【F:config/parameters.yaml†L14-L55】
- `VideoFrameSampler` und andere Services ziehen FFmpeg-/FFprobe-Pfade aus den Parametern, womit Pipeline und Slideshow konsistent auf dieselben Binaries zugreifen.【F:config/services.yaml†L560-L569】
- Feed-Personalisierung nutzt YAML-Profile mit Score-/Member-Limits und Benachrichtigungsplänen für Push/Email; SPA-Settings legen Timeline-/Gesture-/Offline-/Animations-Defaults fest.【F:config/parameters/feed.yaml†L9-L120】【F:config/parameters/feed.yaml†L121-L160】
- Ein zentraler Feature-Flag-Katalog oder einheitliches Namensschema ist nicht vorhanden; Flags werden dezentral über Parameterdateien gepflegt.

## Datenmodell & Persistenz
- `Cluster` speichert Algorithmus, Parameter, Mitglieder (JSON), Fingerprint, Cover-/Location-Relationen, Start/End-Zeiten und Centroid-Informationen, gestützt durch Indizes und Unique Constraints.【F:src/Entity/Cluster.php†L29-L191】
- `Media` hält Pfade, Checksums, pHash/aHash/dHash-Präfixe, Geo-Hashes, Kamera-/Lens-Metadaten, Qualitätskennzahlen, Burst-/Live-Paare und Flags, unterstützt durch zahlreiche Indizes für Lookup-Performance.【F:src/Entity/Media.php†L25-L156】
- `MediaDuplicate` persistiert Hamming-Distanzen zwischen pHash-Kandidaten mit CASCADE-FKs; `Location` modelliert Geocoder-Resultate inklusive Bounding-Box, POIs und Attributionsdaten.【F:src/Entity/MediaDuplicate.php†L22-L142】【F:src/Entity/Location.php†L21-L176】
- Da Cluster-Mitglieder als JSON abgelegt werden, fehlen relationale Member-Tabellen oder Materialized Views für schnelle SQL-Auswertungen.

## Qualitäts-, Ästhetik- & Duplikatheuristiken
- `MediaQualityAggregator` berechnet gewichtete Scores aus Schärfe, Belichtung, ISO, Clipping, Auflösung, wendet Rauschschwellen nach Aufnahmedatum an und markiert Low-Quality-Items inklusive Index-Logs.【F:src/Service/Metadata/Quality/MediaQualityAggregator.php†L24-L107】
- `TimeStage` kombiniert Zeitnormalisierung, Kalender-/Daypart-/Solar-Features und QA-Inspektion, während `TimeNormalizer` EXIF-, Dateiname- und MTime-Fallbacks inklusive Zeitzonen-Validierung orchestriert.【F:src/Service/Indexing/Stage/TimeStage.php†L26-L87】【F:src/Service/Metadata/TimeNormalizer.php†L36-L191】
- `FacePresenceDetector` nutzt Posterframes für Videos, prüft Has-Face/Persons und delegiert an ein Backend; `SimilarityMetrics` aggregiert Zeit-, Distanz-, pHash- und Personen-Overlap zur Diversifizierung.【F:src/Service/Metadata/FacePresenceDetector.php†L24-L125】【F:src/Clusterer/Selection/SimilarityMetrics.php†L31-L149】
- `PerceptualHashExtractor` erstellt 128-Bit-pHash plus aHash/dHash, rotiert nach EXIF/Video und verwendet FFmpeg/FFprobe; `NearDuplicateStage` persistiert Treffer mit konfigurierter Hamming-Schwelle.【F:src/Service/Metadata/PerceptualHashExtractor.php†L47-L176】【F:src/Service/Indexing/Stage/NearDuplicateStage.php†L1-L64】

## Slideshow-Pipeline
- `SlideshowVideoGenerator` erzwingt lesbare Assets, baut FFmpeg-Kommandos mit Übergangs-Whitelist, Ken-Burns-Zoom, Blur/Vignette, Easing, Intro/Outro-Fades und Loudness-Normalisierung; Fehler werden als RuntimeException weitergereicht.【F:src/Service/Slideshow/SlideshowVideoGenerator.php†L77-L239】
- `SlideshowGenerateCommand` verarbeitet JSON-Jobs, bereinigt Lock-/Error-Dateien und meldet Fehler per Job-Log; Konfigurationsparameter definieren Pfade, Dauer, FPS, Transitions, Musikpfad und Audio-Loudness.【F:src/Command/SlideshowGenerateCommand.php†L36-L106】【F:config/parameters.yaml†L930-L956】

## Festgestellte Lücken & Risiken
- Kein dediziertes `memories:curate-vacation`; Operator:innen müssen Cluster- und Feed-Kommandos manuell kombinieren.【09a054†L1-L2】
- Parameter verweisen auf Strategien wie `cityscape_night`, `hike_adventure` oder `snow_vacation_over_years`, für die keine Service-Definition existiert – Konfigurationspfade laufen ins Leere.【F:config/parameters.yaml†L417-L437】【F:config/services.yaml†L535-L708】
- Cluster-Mitglieder werden als JSON gespeichert, wodurch relationale Auswertungen, Member-Indizes oder Materialized Views fehlen und Analyse-Workloads erschwert werden.【F:src/Entity/Cluster.php†L73-L82】
- Feature-Flags sind auf mehrere Parameterdateien verteilt; ohne zentrale Registry steigt der Pflegeaufwand und das Risiko inkonsistenter Bezeichnungen.【F:config/parameters.yaml†L14-L55】
