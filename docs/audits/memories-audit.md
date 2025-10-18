# Memories Pipeline Audit (2025-10-18)

## Zusammenfassung

| Baustein | Status | Hinweise |
| --- | --- | --- |
| Cluster-Strategien & Pipeline | gedeckt | `HybridClusterer` orchestriert alle getaggten Strategien und versieht Entwürfe nach dem Scoring mit Titeln; die Service-Konfiguration deklariert die vollständige Strategieliste inklusive Parametern, Qualitäts-Aggregation und Konsolidator-Pipeline.【F:src/Service/Clusterer/HybridClusterer.php†L34-L174】【F:config/services.yaml†L535-L1108】 |
| Kuration & Selektions-Layer | teilweise | Es existiert kein Namespace `MagicSunday\Memories\Curator`; Selektionsprofile, Stages und Overrides liegen unter `Service\Clusterer\Selection`. Runtime-Overrides und Policy-Auflösung erfolgen über `SelectionPolicyProvider` sowie YAML-Profile.【1bea5f†L1-L4】【F:src/Service/Clusterer/Selection/SelectionPolicyProvider.php†L32-L198】【F:config/services.yaml†L1110-L1158】【F:config/parameters/selection.yaml†L1-L174】 |
| Indexierung & Signalextraktion | gedeckt | `memories:index` unterstützt Force-/Video-/Thumbnail-Modi und nutzt Ingestion-, QA- und Progress-Komponenten; Time-Normalisierung, Qualitätsaggregation und perceptual Hashing liefern Scores, EXIF/Timezone-Daten sowie Duplikatsignaturen.【F:src/Command/IndexCommand.php†L35-L155】【F:src/Service/Metadata/TimeNormalizer.php†L34-L200】【F:src/Service/Metadata/Quality/MediaQualityAggregator.php†L27-L200】【F:src/Service/Metadata/PerceptualHashExtractor.php†L47-L195】 |
| Feed Preview & HTML-Export | gedeckt | `memories:feed:preview` bietet Score-/Member-/Per-Media-Cap-Overrides samt Konsolidierung, `memories:feed:export-html` generiert statische Vorschauen inkl. Thumbnail-Optionen und Symlink-Support.【F:src/Command/FeedPreviewCommand.php†L44-L200】【F:src/Command/FeedExportHtmlCommand.php†L31-L112】 |
| `memories:curate-vacation` | fehlt | Im Command-Verzeichnis existiert kein entsprechender Befehl; Vacation-Kuration erfolgt nur indirekt über Cluster- und Selektionsprofile.【b9d521†L1-L2】 |
| Strategische Gewichtung & Profile | gedeckt | YAML-Parameter definieren Konsolidierungs-Schwellen, Prioritäten aller Strategien sowie Auswahlprofile mit Zielmengen, Abständen und Boni.【F:config/parameters.yaml†L620-L840】【F:config/parameters/selection.yaml†L1-L174】 |
| Feature-Flags & Runtime-Schalter | teilweise | Zahlreiche `%env()%`-gebundene Parameter (z. B. Telemetrie, Face Detection, Slideshow) existieren, jedoch kein zentrales Feature-Flag-Registry oder konsistentes Flag-Namensschema.【F:config/parameters.yaml†L15-L115】【F:config/parameters.yaml†L900-L960】 |
| Datenmodell (Cluster/Media/Memory/Location) | teilweise | Doctrine-Entities modellieren Cluster, Medien, Duplikate, Memories und Locations mit Indizes & FKs; Cluster-Mitglieder liegen jedoch als JSON ohne separate Relation, Materialized Views fehlen.【F:src/Entity/Cluster.php†L29-L191】【F:src/Entity/Media.php†L25-L210】【F:src/Entity/MediaDuplicate.php†L22-L158】【F:src/Entity/Memory.php†L21-L200】【F:src/Entity/Location.php†L21-L200】 |
| Qualitäts-, Ästhetik- & Duplikatheuristiken | gedeckt | Qualitätsscores berücksichtigen Schärfe, Belichtung, ISO, Clipping; Similarity-Metriken bewerten Zeit/GPS/pHash/Personen; pHash-Extraktoren erzeugen aHash/dHash/pHash samt Video-Posterframes.【F:src/Service/Metadata/Quality/MediaQualityAggregator.php†L27-L200】【F:src/Clusterer/Selection/SimilarityMetrics.php†L33-L200】【F:src/Service/Metadata/PerceptualHashExtractor.php†L47-L195】 |
| Slideshow-Pipeline | gedeckt | `slideshow:generate` triggert den FFmpeg-basierten `SlideshowVideoGenerator` mit Ken-Burns, Blur/Vignette, Transition-Whitelist und Audio-Normalisierung; Parameterdatei steuert Pfade, Filter, Transitionen, Timing.【F:src/Command/SlideshowGenerateCommand.php†L33-L107】【F:src/Service/Slideshow/SlideshowVideoGenerator.php†L76-L200】【F:config/parameters.yaml†L900-L960】 |

## Namespaces & Pipelines
- `MagicSunday\Memories\Clusterer` umfasst Strategien, Support-Services und Selektionskomponenten; Build/Score/Title erfolgt im `HybridClusterer` als orchestrierender Einstiegspunkt.【F:src/Service/Clusterer/HybridClusterer.php†L34-L174】【F:config/services.yaml†L535-L1158】
- Ein dedizierter Namespace `MagicSunday\Memories\Curator` fehlt; kuratierende Logik steckt in `Service\Clusterer\Selection` und Konfigurationsprofilen.【1bea5f†L1-L4】【F:src/Service/Clusterer/Selection/SelectionPolicyProvider.php†L32-L198】
- Indexierung und Slideshow-Funktionen liegen unter `Service\Indexing` bzw. `Service\Slideshow`; ein `MagicSunday\Memories\Indexer`- oder `MagicSunday\Memories\Slideshow`-Root-Namespace ist nicht vorhanden, die Funktionalität ist jedoch vollständig implementiert.【1bea5f†L1-L4】【F:src/Command/IndexCommand.php†L35-L155】【F:src/Command/SlideshowGenerateCommand.php†L33-L107】

## CLI-Kommandos & Ablaufsteuerung
- `memories:index` behandelt Force-/Dry-Run-/Video-/Thumbnail-Optionen, zeigt Fortschritt und finalisiert Pipeline-Läufe inklusive QA-Bericht.【F:src/Command/IndexCommand.php†L35-L155】
- `memories:cluster` erlaubt Dry-Run, Limit, Zeitraum-Filter, Replace sowie Vacation-Debug; Telemetrie und Auswahl-Overrides werden integriert ausgegeben.【F:src/Command/ClusterCommand.php†L38-L206】
- `memories:feed:preview` lädt Cluster, konsolidiert optional, respektiert Auswahl-Overrides und rendert Score-/Mitgliedertabellen; `memories:feed:export-html` exportiert statische HTML-Feeds mit Thumbnail-Limits und Symlink-Modus.【F:src/Command/FeedPreviewCommand.php†L44-L200】【F:src/Command/FeedExportHtmlCommand.php†L31-L112】
- `slideshow:generate` verarbeitet JSON-Jobs, erstellt Videos und räumt Lock-/Error-Dateien auf.【F:src/Command/SlideshowGenerateCommand.php†L33-L107】
- Ein `memories:curate-vacation`-Befehl ist nicht vorhanden; Vacation-Kuration erfolgt ausschließlich innerhalb von Strategien/Profilen.【b9d521†L1-L2】

## Strategien & Schwellenwerte
Die YAML-Konfiguration listet alle aktiven Strategien mit Parametern. Auswahl (Auszug):

| Strategie | Kernparameter |
| --- | --- |
| TimeSimilarity | `maxGapSeconds=10800`, `minItemsPerBucket=9`。【F:config/services.yaml†L535-L540】 |
| LocationSimilarity | `radiusMeters=140`, `minItemsPerPlace=6`, `maxSpanHours=16`。【F:config/services.yaml†L542-L548】 |
| DeviceSimilarity | `minItemsPerGroup=5`。【F:config/services.yaml†L550-L554】 |
| PhashSimilarity | `maxHamming=7`, `minItemsPerBucket=2`。【F:config/services.yaml†L584-L589】 |
| Burst | `maxGapSeconds=90`, `maxMoveMeters=45`, `minItemsPerBurst=3`。【F:config/services.yaml†L592-L599】 |
| CrossDimension | `timeGapSeconds=5400`, `radiusMeters=130`, `minItemsPerRun=5`。【F:config/services.yaml†L601-L607】 |
| Panorama / PanoramaOverYears | Aspekt ≥2.3, Session-Gap 9000 s, historische Mindestjahre/Items.【F:config/services.yaml†L609-L624】 |
| PortraitOrientation | `minPortraitRatio=1.2`, Session-Gap 7200 s.【F:config/services.yaml†L626-L632】 |
| VideoStories | `minItemsPerDay=2` mit Location-Hilfe.【F:config/services.yaml†L634-L639】 |
| DayAlbum | `minItemsPerDay=7`。【F:config/services.yaml†L642-L646】 |
| AtHome (Weekend/Weekday) | Home-Koordinaten via `%env()%`, Mindestanteile & Items.【F:config/services.yaml†L648-L670】 |
| Anniversary, PersonCohort, HolidayEvent | Jubiläumsjahre, Personenfenster, Holiday-Minima.【F:config/services.yaml†L673-L695】 |
| Nightlife & NewYearEve | Zeitfenster, Radius, Min-Items pro Nacht.【F:config/services.yaml†L698-L713】 |
| Vacation / WeekendGetaways / TransitTravelDay | Vacation-Score, Staypoint-/Transit-Profile, minTravelKm 70.【F:config/services.yaml†L872-L918】 |
| FirstVisitPlace & SignificantPlace | Grid 0.01°, Tages-/Item-Mindestwerte.【F:config/services.yaml†L921-L938】 |
| Season / SeasonOverYears / GoldenHour | Saisonale Mindestmengen, Golden-Hour-Stunden & Gaps.【F:config/services.yaml†L941-L967】 |
| ThisMonth/OnThisDay/OneYearAgo/YearInReview/MonthlyHighlights | Zeitzonenabhängige Mindestjahre, Items & Tage.【F:config/services.yaml†L970-L1009】 |

Strategie-Prioritäten, Score-Overrides und Konsolidierungs-Schwellen (Merge/Drop, Min-Score, Per-Media-Caps, Keep-Order) werden zentral in `parameters.yaml` gepflegt.【F:config/parameters.yaml†L620-L840】

## Konsolidierung & Auswahl
- Konsolidierungsstufen (FilterNormalization, MemberRanking, DuplicateCollapse, Nesting, Dominance, Overlap, AnnotationPruning, PerMediaCap, CanonicalTitle) sind via `memories.cluster_consolidation.stage` getaggt und werden in `PipelineClusterConsolidator` sequenziell ausgeführt.【F:config/services.yaml†L1022-L1108】
- Hard/Soft-Selection-Stages (DayQuota, TimeGap, TimeSlotDiversification, StaypointQuota, pHash/Scene/Orientation/People) ergänzen den Policy-gesteuerten Member-Selector.【F:config/services.yaml†L1117-L1156】
- `SelectionPolicyProvider` verarbeitet Profile, Laufzeit-Overrides und Run-Length-Constraints; Profile definieren Ziel- und Mindestmengen, Hamming-Limits, Qualitätsböden und Boni.【F:src/Service/Clusterer/Selection/SelectionPolicyProvider.php†L32-L198】【F:config/parameters/selection.yaml†L1-L174】

## Konfiguration & Feature-Flags
- Indexing-, Metadata- und Face-Detection-Parameter (Hash-Längen, Telemetrie-Flags, Detector-Pfade) sowie Home-/Transit-/Vacation-Einstellungen werden per `%env()%` übersteuerbar bereitgestellt.【F:config/parameters.yaml†L15-L180】
- Feed-Personalisierung (Score-Limits, Profile, HTTP-Defaults) und SPA-Einstellungen liegen in `parameters/feed.yaml` mit klaren Mindest-/Maximalwerten.【F:config/parameters/feed.yaml†L9-L144】
- Slideshow-Parameter (FFmpeg/Pfade, Ken-Burns, Blur/Vignette, Transition-Whitelist, Audio-Loudness) sichern reproduzierbare Videoausgaben.【F:config/parameters.yaml†L900-L960】

## Datenmodell & Persistenz
- `cluster` speichert Algorithmus, Parameter, Mitglieder (JSON), Fingerprint, Cover/Location-Refs, Versionen und Centroid-Indizes.【F:src/Entity/Cluster.php†L29-L168】
- `media` hält umfangreiche Signale inkl. Checksums, phash/a/dhash-Präfixe, Burst-/Live-Paar-Indizes, Geo-Hashes, Kamera- und Qualitätsmetriken.【F:src/Entity/Media.php†L25-L210】
- `media_duplicate` erfasst pHash-Hamming-Distanzen als CASCADE-verknüpfte Paare.【F:src/Entity/MediaDuplicate.php†L22-L158】
- `memory` speichert kuratierte Stories mit Score, HTML-Preview und optionalem Cluster-Bezug; `location` modelliert Geocoder-Resultate mit Bounding-Box, POIs und Indizes.【F:src/Entity/Memory.php†L21-L200】【F:src/Entity/Location.php†L21-L200】
- Separate Tabellen für Cluster-Mitglieder oder Materialized Views existieren nicht; Analysen benötigen JSON-Auswertung oder Replikation.

## Qualitäts- & Duplikatheuristiken
- Qualitätsbewertung kombiniert Auflösung, Schärfe, Belichtung, ISO-Rauschen, Clipping und setzt Low-Quality-Flags; Schwellenwerte lassen sich über Parameter justieren.【F:src/Service/Metadata/Quality/MediaQualityAggregator.php†L27-L200】【F:config/parameters.yaml†L800-L816】
- `SimilarityMetrics` liefert Zeit-, Distanz-, pHash- und Personenüberschneidungswerte für Diversifizierung & Duplikat-Kollaps.【F:src/Clusterer/Selection/SimilarityMetrics.php†L33-L200】
- `PerceptualHashExtractor` generiert pHash/aHash/dHash (inkl. Posterframes) anhand konfigurierbarer DCT-Größen und Präfix-Längen.【F:src/Service/Metadata/PerceptualHashExtractor.php†L47-L195】

## Slideshow-Pipeline
- `SlideshowVideoGenerator` validiert Assets, arbeitet mit Transition-Whitelist & Gewichtungen, Ken-Burns (Easing, Zoom), Blur/Vignette-Pfaden, Beat-Grid und Audio-Limiter; Fehler werden Job-basiert protokolliert.【F:src/Service/Slideshow/SlideshowVideoGenerator.php†L76-L200】
- Konfigurierbare Parameter (Dauer, FPS, Blur, Textbox, Musikpfad, FFmpeg/FFprobe) sind per `%env()%` überschreibbar.【F:config/parameters.yaml†L900-L960】

## Festgestellte Lücken & Risiken
- Kein konsolidierender `memories:curate`- oder `memories:curate-vacation`-Befehl; Operator:innen müssen Index→Cluster→Feed→Export manuell kombinieren.【b9d521†L1-L2】【F:src/Command/ClusterCommand.php†L38-L206】
- Strategien wie `cityscape_night`, `snow_day`, `hike_adventure` sind in den Prioritäten hinterlegt, aber ohne Service-Definition – hier drohen tote Konfigurationspfade.【F:config/parameters.yaml†L748-L778】【F:config/services.yaml†L535-L1009】
- Cluster-Mitglieder werden als JSON persistiert; fehlende Relationstabellen erschweren SQL-Auswertungen und Indizierung.【F:src/Entity/Cluster.php†L29-L191】
- Feature-Flags sind dezentral als Einzelparameter verteilt; ein zentrales Flag-Registry oder konsistentes Naming würde die Steuerbarkeit verbessern.【F:config/parameters.yaml†L15-L115】
