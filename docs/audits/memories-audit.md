# Memories Pipeline Audit (2025-02-14)

## Zusammenfassung

| Baustein | Status | Hinweise |
| --- | --- | --- |
| Indexierung & Signalextraktion | gedeckt | `memories:index` orchestriert Ingestion, inklusive Telemetrie-Reset und optionalem Thumbnail-/Video-Support; Time-Normalisierung, Qualitäts-/Belichtungsmetriken und Perzeptions-Hashes werden während der Pipeline berechnet.【F:src/Command/IndexCommand.php†L24-L129】【F:src/Service/Metadata/TimeNormalizer.php†L12-L192】【F:src/Service/Metadata/Quality/MediaQualityAggregator.php†L27-L190】【F:src/Service/Metadata/PerceptualHashExtractor.php†L48-L175】 |
| Cluster-Strategien | gedeckt | HybridClusterer lädt >25 Strategien (Zeit, Ort, Personen, Panorama etc.) mit expliziten Parametern/Schwellen aus `services.yaml`; Titelgenerator wird nach dem Scoring angewandt.【F:src/Service/Clusterer/HybridClusterer.php†L12-L174】【F:config/services.yaml†L535-L1016】【F:config/parameters.yaml†L760-L799】 |
| Konsolidierung & Member-Auswahl | gedeckt | PipelineClusterConsolidator führt Normalisierung, Qualitätsranking, Duplicate-Collapse, Dominanz-/Overlap-Resolver und Per-Media-Cap anhand konfigurierter Schwellen aus; Auswahlstufen erzwingen Diversifizierung (Zeit, Staypoints, pHash, Personen).【F:config/services.yaml†L1018-L1158】【F:src/Service/Clusterer/Pipeline/PipelineClusterConsolidator.php†L15-L45】【F:src/Service/Clusterer/Pipeline/DominanceSelectionStage.php†L12-L160】 |
| Scoring & Ranking | gedeckt | CompositeClusterScorer kombiniert Quality, People, Content, Novelty, Holiday, Recency etc. mit Gewichtsmatrix und algorithmischen Boosts; Qualitätsbasis 12 MP sowie weitere Schwellen steuern Aggregation.【F:config/services.yaml†L1167-L1288】【F:config/parameters.yaml†L800-L848】 |
| Feed Preview & Export | gedeckt | CLI-Befehle `memories:feed:preview` und `memories:feed:export-html` unterstützen Auswahl-Overrides, Konsolidierungscaps, Limitierung sowie HTML-Export mit Thumbnail-Einstellungen.【F:src/Command/FeedPreviewCommand.php†L45-L200】【F:src/Command/FeedExportHtmlCommand.php†L24-L83】 |
| Slideshow-Pipeline | gedeckt | FFmpeg-basierter Generator unterstützt Ken-Burns, Transition-Whitelist, Blur/Vignette, Audio-Normalisierung und Cleanup-Handling, konfiguriert via Parameterdatei und Umgebungsvariablen.【F:src/Command/SlideshowGenerateCommand.php†L24-L83】【F:src/Service/Slideshow/SlideshowVideoGenerator.php†L48-L195】【F:config/parameters.yaml†L850-L956】 |
| Feature-Flags & Runtime-Schalter | teilweise | Einzelne Komponenten (z. B. Metadata-Telemetrie, Face Detection, Ken-Burns) lesen `%env()%`-Overrides; ein zentraler Memories-Feature-Layer existiert jedoch nicht.【F:config/parameters.yaml†L16-L41】【F:config/parameters.yaml†L913-L927】 |
| Ganzheitlicher Orchestrator (`memories:curate`) | fehlt | Aktuell getrennte Befehle für Indexing, Clustering, Feed, Slideshow; kein Command der sämtliche Stufen (Index → Cluster → Kuratierung → Export) zusammenfasst.【F:src/Command/ClusterCommand.php†L38-L155】【F:src/Command/IndexCommand.php†L32-L119】 |
| Stage-weise Feed-/HTML-Vorschau | fehlt | Weder `memories:feed:preview` noch `memories:feed:export-html` besitzen einen `--stage`-Schalter oder rendern Zwischenstände der Konsolidierung.【F:src/Command/FeedPreviewCommand.php†L69-L200】【F:src/Command/FeedExportHtmlCommand.php†L41-L78】 |
| Datenmodell (Cluster/Mitglieder, Significant Places) | teilweise | Doctrine-Entities `cluster`, `media`, `memory`, `location`, `media_duplicate` decken Grundfunktionen ab; dedizierte Tabellen `memories_cluster_member` oder `memories_significant_place` fehlen, Mitglieder stecken als JSON im Cluster.【F:src/Entity/Cluster.php†L29-L191】【F:src/Entity/Media.php†L25-L188】【F:src/Entity/MediaDuplicate.php†L15-L92】【F:src/Entity/Memory.php†L21-L190】 |
| Telemetrie & Explainability | teilweise | Clusterlauf erzeugt Konsolen-Telemetrie und Monitoring-Events; strukturierte Model-Cards/Explain-Reports oder globale JSON-Logs pro Schritt sind nicht vorhanden.【F:src/Command/ClusterCommand.php†L167-L206】【F:src/Service/Clusterer/Pipeline/DominanceSelectionStage.php†L93-L119】 |

## Details

### CLI & Ablaufsteuerung
- `memories:index` ermöglicht erzwungenes Reindexing, Dry-Run, Thumbnails, Video-Indizierung, Strict-MIME und Progress-Bar; pro Datei wird das Ergebnis über die Pipeline verarbeitet.【F:src/Command/IndexCommand.php†L32-L118】 
- `memories:cluster` führt Strategieläufe mit Fortschrittsanzeigen, Telemetrie und optionaler Löschung bestehender Cluster aus; Vacation-Debug lässt sich aktivieren.【F:src/Command/ClusterCommand.php†L38-L163】 
- Feed-Werkzeuge (`memories:feed:preview`, `memories:feed:export-html`) bieten Limit-/Score-Parameter, Profil-Overrides sowie optionales Symlinken von Thumbnails.【F:src/Command/FeedPreviewCommand.php†L69-L200】【F:src/Command/FeedExportHtmlCommand.php†L41-L83】 
- Slideshow-Jobs werden über `slideshow:generate` mit Fehler-Logging und Lockfile-Cleanup verarbeitet.【F:src/Command/SlideshowGenerateCommand.php†L24-L83】 

### Indexierung & Signale
- Zeit-Normalisierung nutzt priorisierte Quellen (EXIF, QuickTime) und fällt auf Dateiname bzw. Dateisystem-Zeit zurück; Zeitzone und Offset werden ergänzt, inkl. Plausibilitätsprüfung.【F:src/Service/Metadata/TimeNormalizer.php†L36-L192】 
- Qualitätsaggregation kombiniert Schärfe, Belichtung (Brightness/Contrast), ISO-Rauschen und Clipping zu Scores; Schwellwerte kennzeichnen Low-Quality-Medien.【F:src/Service/Metadata/Quality/MediaQualityAggregator.php†L27-L105】 
- Perzeptuelle Hashes (pHash/aHash/dHash) inklusive Video-Posterframes (ffmpeg/ffprobe) unterstützen Duplikaterkennung; Hashpräfixe werden gespeichert.【F:src/Service/Metadata/PerceptualHashExtractor.php†L48-L175】 
- `Media`-Entity persistiert umfangreiche Signale: Zeit, GPS, Kamera, Hashes (xxHash64, pHash), Burst-IDs, Live-Paare, Qualitätsmetriken, Flags für Low Quality/No-Show.【F:src/Entity/Media.php†L25-L188】 

### Clustering & Konsolidierung
- DefaultClusterJobRunner lädt Medien, prüft Feature-Versionen, führt alle Strategien via HybridClusterer aus und übergibt Drafts an den Konsolidator, inklusive Telemetrie der Top-Cluster.【F:src/Service/Clusterer/DefaultClusterJobRunner.php†L12-L200】 
- HybridClusterer orchestriert Strategien, erstellt Fortschritts-Handles, ruft `CompositeClusterScorer` auf und generiert Titel/Subtitel pro Cluster.【F:src/Service/Clusterer/HybridClusterer.php†L34-L154】 
- Strategien werden in `services.yaml` mit Parametern (z. B. maxGapSeconds, minItems, Radius) registriert, Prioritäten stammen aus `parameters.yaml`. Umfang umfasst Zeit-/Ort-/Personen-Highlights, Panoramen, Saisons, Jubiläen, Reisen etc.【F:config/services.yaml†L535-L1016】【F:config/parameters.yaml†L760-L799】 
- Konsolidierungsstufen decken Qualitätsschwellen, Duplicate-Zusammenführung, Nesting, Dominanz-/Overlap-Regeln, Annotation-Pruning, Per-Media-Caps und Titelerstellung ab.【F:config/services.yaml†L1018-L1109】 
- Member-Selection nutzt harte/soft Stages (Tagesquoten, Zeit-Slots, Staypoints, pHash-Diversität, Szenen-/Orientierungs-/Personen-Balance) gemäß Policy-Profile.【F:config/services.yaml†L1110-L1158】【F:config/parameters/selection.yaml†L1-L78】 

### Scoring & Feed
- CompositeClusterScorer mischt Heuristiken (Qualität, People, Content, Location, POI, Novelty, Holiday, Recency, Density, Time Coverage) mit spezifischen Gewichten und Algorithmus-Boosts; `memories.score.*` definiert Schwellen/Boosts.【F:config/services.yaml†L1167-L1287】【F:config/parameters.yaml†L800-L848】 
- Feed-Personalisation konfiguriert Mindestscore, Mitgliederzahlen, Per-Algorithmus-Limits und Bonus/Penalty-Werte pro Zeitfenster; Profile (default, familienfreundlich, reisen) verfügbar.【F:config/parameters/feed.yaml†L4-L60】 
- FeedPreviewCommand bietet Konsolidierungscap und Score-/Member-Filter zur schnellen Bewertung; ExportCommand erstellt statische HTML mit Thumbnails/Symlinks.【F:src/Command/FeedPreviewCommand.php†L69-L200】【F:src/Command/FeedExportHtmlCommand.php†L41-L83】 

### Datenmodell & Speicherung
- `cluster` speichert Algorithmus, Parameter, Centroid (auch lat/lon/cell), Mitgliederliste, Cover, Location-Referenz, Fingerprint, Photo/Video-Counts.【F:src/Entity/Cluster.php†L29-L168】 
- Cluster-Mitglieder verbleiben als JSON; es gibt keine separate `cluster_member`-Tabelle – zukünftige Migration erforderlich. 
- `media_duplicate` persistiert pHash-Hamming-Distanzen zwischen Medien (CASCADE-FKs).【F:src/Entity/MediaDuplicate.php†L15-L92】 
- `memory` hält kuratierte Stories mit Score, HTML-Vorschau und Zeitfenster, jedoch ohne detaillierte Mitgliedertabelle.【F:src/Entity/Memory.php†L21-L190】 
- `location` modelliert Geocoding-Resultate mit Bounding-Box, POIs, Confidence, Timezone.【F:src/Entity/Location.php†L19-L199】 

### Slideshow & Multimedia
- SlideshowVideoGenerator verwaltet Transition-Whitelist, Ken-Burns-Zoom (1.0–1.08), Blur/Vignette-Parameter, Audio-Fades, Zufalls-Seed (Randomizer) sowie Fehlerprotokollierung; Parameterdatei steuert Dauer, Zoom, Filter, Musik-Pfad und FFmpeg-Binärpfade.【F:src/Service/Slideshow/SlideshowVideoGenerator.php†L48-L195】【F:config/parameters.yaml†L850-L956】 

### Telemetrie & Monitoring
- ClusterCommand rendert Stage-Statistiken, Warnungen und Top-Cluster inklusive Score/Zeitraum; Telemetrie wird über `ClusterJobTelemetry` gespeist.【F:src/Command/ClusterCommand.php†L167-L206】 
- DominanceSelectionStage emittiert Monitoring-Events (`selection_start`, `selection_completed`) mit Merge/Drop-Thresholds – globales Log-File ist jedoch optional/teilweise.【F:src/Service/Clusterer/Pipeline/DominanceSelectionStage.php†L93-L119】 

### Lücken & Risiken
- Kein vereinheitlichter `memories:curate`-Befehl; Abläufe müssen manuell kombiniert werden.【F:src/Command/ClusterCommand.php†L38-L155】【F:src/Command/IndexCommand.php†L32-L119】 
- Feed-/Export-Kommandos kennen keine Stage-Parameter (`--stage`), wodurch Zwischenstände (raw/merged/curated) nicht isoliert visualisierbar sind.【F:src/Command/FeedPreviewCommand.php†L69-L200】【F:src/Command/FeedExportHtmlCommand.php†L41-L83】 
- Datenmodell ohne separate Cluster-Mitgliedertabelle oder Significant-Place-Persistenz erschwert SQL-Abfragen und Migrationsanpassungen.【F:src/Entity/Cluster.php†L29-L191】 
- Feature-Flag-Verwaltung verteilt sich auf Einzelparameter (`memories.metadata.pipeline.telemetry`, `memories.slideshow.*`); kein konsolidiertes Flag-Layer vorhanden.【F:config/parameters.yaml†L16-L41】【F:config/parameters.yaml†L913-L927】 
- Explain-/Model-Card-Ausgabe, deterministische Seeds für Slideshows (teilweise) und strukturierte JSON-Logs pro Pipeline-Schritt fehlen noch, obwohl Monitoring-Hooks existieren.【F:src/Service/Slideshow/SlideshowVideoGenerator.php†L48-L195】【F:src/Service/Clusterer/Pipeline/DominanceSelectionStage.php†L93-L119】 

