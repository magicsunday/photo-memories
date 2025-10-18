# Memories Pipeline Audit (2025-10-19)

## Summary

| Baustein | Status | Hinweise |
| --- | --- | --- |
| Cluster-Strategien & Hybrid-Pipeline | gedeckt | `HybridClusterer` orchestriert alle getaggten Strategien, scored Entwürfe und vergibt Titel nach Abschluss; `config/services.yaml` deklariert Zeit-, Orts-, Personen-, Event- und Motiv-Heuristiken inklusive Qualitätsaggregation und Video-sensitiver Parameterisierung.【F:src/Service/Clusterer/HybridClusterer.php†L34-L174】【F:config/services.yaml†L535-L743】 |
| Kuration & Selektions-Layer | teilweise | Es existiert kein separater Namespace `MagicSunday\\Memories\\Curator`; Selektionsprofile, Laufzeit-Overrides und Hard-/Soft-Stages liegen unter `Service\\Clusterer\\Selection` und werden über YAML-Profile gesteuert.【F:src/Service/Clusterer/Selection/SelectionPolicyProvider.php†L32-L199】【F:config/services.yaml†L1110-L1158】【F:config/parameters.yaml†L320-L455】 |
| Indexierung & Signalextraktion | gedeckt | Die Default-Media-Ingestion-Pipeline sequentiert MIME-Check, Zeit-/Geo-Normalisierung, Qualitäts-, Hash- und Duplikatstufen sowie QA-Logging und Persistenz; `memories:index` bietet Force/Dry-Run/Video/Thumbnail-Optionen samt QA-Report-Ausgabe.【F:src/Service/Indexing/DefaultMediaIngestionPipeline.php†L32-L125】【F:config/services.yaml†L390-L408】【F:src/Command/IndexCommand.php†L35-L170】 |
| Feed Preview & HTML-Export | gedeckt | `memories:feed:preview` erlaubt Score-, Member- und Per-Media-Cap-Overrides inklusive Konsolidierungsausgabe; `memories:feed:export-html` erzeugt statische Feeds mit Limit-/Thumbnail-Steuerung und Symlink-Modus.【F:src/Command/FeedPreviewCommand.php†L45-L220】【F:src/Command/FeedExportHtmlCommand.php†L32-L113】 |
| Orchestrator `memories:curate` | gedeckt | Das Kommando verkettet Indexierung, Clustering und Feed-Export, akzeptiert Medienpfad-Overrides, Datumsfilter (`--since/--until`), gruppenbasierte Kuration (`--types`) sowie Reindex-Steuerung (`--reindex=auto|force|skip`) und Dry-Run.【F:src/Command/MemoriesCurateCommand.php†L53-L203】【F:src/Command/MemoriesCurateCommand.php†L210-L304】 |
| Strategische Gewichtung & Prioritäten | gedeckt | YAML-Parameter definieren Konsolidierungs-Schwellen, Keep-Order-Gruppen, Klassifikations-Prioritäten und Boosts pro Strategie bzw. Algorithmus.【F:config/parameters.yaml†L470-L633】【F:config/parameters.yaml†L725-L799】 |
| Feature-Flags & Runtime-Schalter | teilweise | Zahlreiche `%env()%`-basierte Schalter (Indexing-, Video-, Face-Detection-, Telemetrie- und Slideshow-Settings) existieren, jedoch ohne zentrale Flag-Registry oder vereinheitlichte Namenskonvention.【F:config/parameters.yaml†L14-L118】【F:config/services.yaml†L560-L569】 |
| Datenmodell (Cluster/Media/Location/Duplikate) | teilweise | Doctrine-Entities modellieren Cluster, Medien, Duplikate und Locations mit Indizes/FKs; Cluster-Mitglieder verbleiben jedoch als JSON-Feld ohne relationale Auflösung oder Materialized Views.【F:src/Entity/Cluster.php†L29-L191】【F:src/Entity/Media.php†L25-L334】【F:src/Entity/MediaDuplicate.php†L22-L118】【F:src/Entity/Location.php†L21-L176】 |
| Qualitäts-, Ästhetik- & Duplikat-Heuristiken | gedeckt | Qualitätsscores berücksichtigen Schärfe, Belichtung, ISO, Clipping und zeitabhängige Rausch-Schwellen; Similarity-Metriken kombinieren Zeit/GPS/pHash/Personen; pHash/aHash/dHash-Extraktion plus Near-Duplicate-Stage persistiert Hamming-Distanzen.【F:src/Service/Metadata/Quality/MediaQualityAggregator.php†L24-L105】【F:src/Clusterer/Selection/SimilarityMetrics.php†L31-L149】【F:src/Service/Metadata/PerceptualHashExtractor.php†L47-L208】【F:src/Service/Indexing/Stage/NearDuplicateStage.php†L1-L84】 |
| Slideshow-Pipeline | gedeckt | `slideshow:generate` triggert den FFmpeg-basierten `SlideshowVideoGenerator` mit Ken-Burns, Blur/Vignette, Transition-Whitelist und Audio-Normalisierung; Parameterdateien steuern Pfade, Filter, Transitionen, Musik und Loudness.【F:src/Command/SlideshowGenerateCommand.php†L34-L107】【F:src/Service/Slideshow/SlideshowVideoGenerator.php†L77-L258】【F:config/parameters.yaml†L850-L912】 |

## Namespaces & Pipelines
- `MagicSunday\Memories\Clusterer` und `Service\Clusterer` kapseln Strategien, Quality-Aggregation, Scoring und Titelerzeugung; `HybridClusterer` läuft alle Strategien und versieht Entwürfe nach dem Score-Lauf mit Titel/Untertitel.【F:src/Service/Clusterer/HybridClusterer.php†L34-L149】【F:config/services.yaml†L535-L743】
- Kuratierende Logik (Policy-Profile, Hard-/Soft-Stages, Overrides) lebt unter `Service\Clusterer\Selection`; ein dedizierter `Curator`-Namespace fehlt, die Funktionalität ist dort dennoch vollständig abgebildet.【F:src/Service/Clusterer/Selection/SelectionPolicyProvider.php†L32-L199】【F:config/services.yaml†L1110-L1158】
- Indexierung erfolgt unter `Service\Indexing` mit einer fest verdrahteten Stage-Pipeline inkl. Burst/Live-, Face- und Scene-Heuristiken sowie Hash- und Persistenzschritten.【F:config/services.yaml†L390-L408】
- Slideshow-Funktionalität konzentriert sich im Namespace `Service\Slideshow`; Videoerzeugung, Statusobjekte und Übergangslogik sind dort gebündelt.【F:src/Service/Slideshow/SlideshowVideoGenerator.php†L77-L258】

## CLI-Kommandos & Ablaufsteuerung
- `memories:index` orchestriert Locator, Pipeline, QA-Reporting sowie Force/Dry-Run/Video/Thumbnail-Optionen und zeigt Feature-Version sowie Fortschritt an.【F:src/Command/IndexCommand.php†L35-L152】
- `memories:cluster` (separat dokumentiert) unterstützt Selection-Overrides; `memories:feed:preview` und `memories:feed:export-html` decken Konsolidierung, Personalisierung und statische Exporte ab.【F:src/Command/FeedPreviewCommand.php†L45-L220】【F:src/Command/FeedExportHtmlCommand.php†L32-L113】
- `memories:curate` führt Indexierung, Clustering und den HTML-Feed-Export sequenziell aus, unterstützt Datums- und Typfilter, steuert Reindexing (auto|force|skip) und respektiert Dry-Runs für gefahrlose Testläufe.【F:src/Command/MemoriesCurateCommand.php†L53-L203】【F:src/Command/MemoriesCurateCommand.php†L210-L304】

## Strategien, Scoring & Konsolidierung
- Strategien decken Zeit-/Orts-Nähe, Geräte, pHash, Burst, Panorama, Porträt, Video, Home, Anniversary, Personen, Feiertage, Nightlife sowie Travel/Vacation-Algorithmen ab und werden via `memories.cluster_strategy` getaggt.【F:config/services.yaml†L535-L743】
- Konsolidierungs-Pipeline umfasst Filter-Normalisierung, Qualitäts-Ranking, Member-Kuration, Duplicate-Collapse, Nesting, Dominanz-/Overlap-Handling, Annotation-Pruning, Per-Media-Cap und Titelkanonisierung.【F:config/services.yaml†L1010-L1108】
- Scoring-Heuristiken kombinieren Temporal-, Qualitäts-, People-, Content-, Location-, POI-, Novelty-, Holiday-, Recency- und Density-Scores mit gewichteten Boosts pro Algorithmus.【F:config/services.yaml†L1172-L1254】【F:config/parameters.yaml†L669-L799】
- YAML-Parameter definieren Konsolidierungs-Schwellen, Keep-Order-Listen, Gruppen, Annotate-only-Typen und Mindestanteile für einzigartige Medien, um die Konsolidierungslogik zu steuern.【F:config/parameters.yaml†L470-L633】

## Konfiguration & Feature-Flags
- `%env()`-Overrides steuern Index-Batches, Video-Posterframes, FFmpeg/FFprobe-Pfade, Face-Detection-Binaries, Hash-Längen, Geocoding-Limits und Telemetrie-Schalter für Metadaten-Pipelines.【F:config/parameters.yaml†L14-L118】
- `VideoFrameSampler` und andere Services beziehen FFmpeg-/FFprobe-Pfade konsistent aus den Parametern, sodass Pipeline und Slideshow dieselben Binaries nutzen.【F:config/services.yaml†L560-L569】
- Feed-Personalisierung nutzt YAML-Profile mit Score-/Member-Limits und Benachrichtigungsplänen für Push/Email; SPA-Settings legen Timeline-, Gesten-, Offline- und Animations-Defaults fest.【F:config/parameters/feed.yaml†L9-L160】
- Eine zentrale Feature-Flag-Registry oder einheitliches Namensschema existiert nicht; Flags werden dezentral über Parameterdateien gepflegt.【F:config/parameters.yaml†L14-L118】

## Datenmodell & Persistenz
- `Cluster` speichert Algorithmus, Parameter, Mitglieder (JSON), Fingerprint, Cover-/Location-Relationen, Start/End-Zeiten und Centroid-Informationen, gestützt durch Indizes und Unique Constraints.【F:src/Entity/Cluster.php†L29-L191】
- `Media` hält Pfade, Checksums, pHash/aHash/dHash-Präfixe, Geo-Hashes, Kamera-/Lens-Metadaten, Qualitätskennzahlen, Burst-/Live-Paare und Flags, unterstützt durch zahlreiche Indizes für Lookup-Performance.【F:src/Entity/Media.php†L25-L334】
- `MediaDuplicate` persistiert Hamming-Distanzen zwischen pHash-Kandidaten mit CASCADE-FKs; `Location` modelliert Geocoder-Resultate inklusive Bounding-Box, POIs und Attributionsdaten.【F:src/Entity/MediaDuplicate.php†L22-L118】【F:src/Entity/Location.php†L21-L176】
- Da Cluster-Mitglieder als JSON abgelegt werden, fehlen relationale Member-Tabellen oder Materialized Views für schnelle SQL-Auswertungen.【F:src/Entity/Cluster.php†L73-L191】

## Qualitäts-, Ästhetik- & Duplikatheuristiken
- `MediaQualityAggregator` berechnet gewichtete Scores aus Schärfe, Belichtung, ISO, Clipping, Auflösung und zeitabhängigem Rausch-Schwellenwert, markiert Low-Quality-Items und schreibt Qualitätslogs.【F:src/Service/Metadata/Quality/MediaQualityAggregator.php†L24-L105】
- `TimeStage` kombiniert Zeitnormalisierung, Kalender-/Daypart-/Solar-Features und QA-Inspektion, während `TimeNormalizer` EXIF-, Dateiname- und MTime-Fallbacks inklusive Zeitzonenvalidierung orchestriert.【F:config/services.yaml†L400-L405】【F:src/Service/Metadata/TimeNormalizer.php†L34-L186】
- `FacePresenceDetector` nutzt Posterframes für Videos, prüft Has-Face/Persons und delegiert an ein Backend; `SimilarityMetrics` aggregiert Zeit-, Distanz-, pHash- und Personen-Overlap zur Diversifizierung.【F:src/Service/Metadata/FacePresenceDetector.php†L24-L125】【F:src/Clusterer/Selection/SimilarityMetrics.php†L31-L149】
- `PerceptualHashExtractor` erstellt 128-Bit-pHash plus aHash/dHash, orientiert nach EXIF/Video und verwendet FFmpeg/FFprobe; `NearDuplicateStage` persistiert Treffer mit konfigurierter Hamming-Schwelle.【F:src/Service/Metadata/PerceptualHashExtractor.php†L47-L208】【F:src/Service/Indexing/Stage/NearDuplicateStage.php†L1-L84】

## Slideshow-Pipeline
- `SlideshowVideoGenerator` erzwingt lesbare Assets, baut FFmpeg-Kommandos mit Übergangs-Whitelist, Ken-Burns-Zoom, Blur/Vignette, Easing, Intro/Outro-Fades und Loudness-Normalisierung; Fehler werden als RuntimeException weitergereicht.【F:src/Service/Slideshow/SlideshowVideoGenerator.php†L77-L258】
- `SlideshowGenerateCommand` verarbeitet JSON-Jobs, bereinigt Lock-/Error-Dateien und meldet Fehler per Job-Log; Konfigurationsparameter definieren Pfade, Dauer, FPS, Transitionen, Musikpfad und Audio-Loudness.【F:src/Command/SlideshowGenerateCommand.php†L34-L107】【F:config/parameters.yaml†L850-L912】

## Festgestellte Lücken & Risiken
- `memories:curate` schreibt Export-Limits (5000 Cluster, 60 Karten, 16 Bilder) sowie das Ausgabeverzeichnis `var/export` fest; alternative Feed-Layouts oder Pfade erfordern derzeit Codeanpassungen.【F:src/Command/MemoriesCurateCommand.php†L462-L497】
- Parameter verweisen auf Strategien wie `cityscape_night`, `hike_adventure` oder `snow_vacation_over_years`, für die keine Service-Definition existiert – diese Konfigurationspfade laufen ins Leere.【F:config/parameters.yaml†L417-L447】【F:config/services.yaml†L535-L743】
- Cluster-Mitglieder werden als JSON gespeichert, wodurch relationale Auswertungen, Member-Indizes oder Materialized Views fehlen und Analyse-Workloads erschwert werden.【F:src/Entity/Cluster.php†L73-L191】
- Feature-Flags sind auf mehrere Parameterdateien verteilt; ohne zentrale Registry steigt der Pflegeaufwand und das Risiko inkonsistenter Bezeichnungen.【F:config/parameters.yaml†L14-L118】
