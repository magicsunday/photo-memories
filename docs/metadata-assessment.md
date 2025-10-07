# Bewertung der Metadaten-Pipeline

## Stärken der aktuellen Implementierung
- Die `CompositeMetadataExtractor`-Orchestrierung gewährleistet eine feste Aufrufreihenfolge und injizierbare Einzel-Extractor-Komponenten, wodurch neue Module ohne Eingriff in den Index-Befehl ergänzt werden können.【F:src/Service/Metadata/CompositeMetadataExtractor.php†L21-L68】
- `TimeNormalizer` vereinheitlicht Zeitstempel inklusive Rückfallstrategien für Dateinamen und Dateisystem und dokumentiert Entscheidungen im Index-Log, was die Nachvollziehbarkeit erhöht.【F:src/Service/Metadata/TimeNormalizer.php†L28-L143】
- Zeit-Features wie `DaypartEnricher`, `SolarEnricher` und `CalendarFeatureEnricher` reichern Medien mit relevanten Kontextinformationen an und kapseln domänenspezifische Logik in klar abgegrenzten Klassen.【F:src/Service/Metadata/DaypartEnricher.php†L21-L53】【F:src/Service/Metadata/SolarEnricher.php†L35-L158】【F:src/Service/Metadata/CalendarFeatureEnricher.php†L24-L115】
- `FfprobeMetadataExtractor` nutzt ffprobe zielgerichtet, um Videostream-Details und QuickTime-Metadaten zu erkennen, inklusive Normalisierung tief verschachtelter Strukturen.【F:src/Service/Metadata/FfprobeMetadataExtractor.php†L47-L480】
- Der `ContentClassifierExtractor` deckt häufige Spezialfälle (Screenshot, Dokument, Karte, Screen Recording) mit heuristischen Regeln ab, die sowohl Dateinamen- als auch Bildmetriken kombinieren.【F:src/Service/Metadata/ContentClassifierExtractor.php†L36-L321】

## Verbesserungsfelder & Aufgabenlisten

### 1. Orchestrierung & Fehlertoleranz
- [x] Fehlerpfade im MIME-Vorab-Guessing vereinheitlichen (z. B. Exception-Handling statt Error-Suppression bei `mime_content_type`) und Logging ergänzen.【F:src/Service/Metadata/CompositeMetadataExtractor.php†L53-L124】【F:test/Unit/Service/Metadata/CompositeMetadataExtractorTest.php†L19-L120】
- [x] Konfigurierbare Pipeline-Schritte einführen (aktiv/deaktiv) sowie Telemetrie sammeln, um Kosten pro Extractor zu messen.【F:config/parameters.yaml†L15-L17】【F:config/services.yaml†L233-L246】【F:src/Service/Metadata/CompositeMetadataExtractor.php†L33-L140】【F:src/Service/Metadata/MetadataExtractorPipelineConfiguration.php†L13-L66】【F:src/Service/Metadata/MetadataExtractorTelemetry.php†L13-L54】【F:test/Unit/Service/Metadata/CompositeMetadataExtractorTest.php†L19-L192】
- [x] Einen Recovery-Pfad dokumentieren, falls einzelne Extractor-Aufrufe scheitern (z. B. Retry-Strategie oder Eskalation an QA).【F:src/Service/Metadata/CompositeMetadataExtractor.php†L79-L126】【F:test/Unit/Service/Metadata/CompositeMetadataExtractorTest.php†L114-L161】

### 2. Zeit- und Zeitzonen-Normalisierung
- [x] `TimeNormalizer` um Quellen-Priorisierungskonfiguration erweitern, damit Deployments alternative Reihenfolgen (z. B. bevorzugte GPS-Zeitzone) festlegen können.【F:src/Service/Metadata/TimeNormalizer.php†L33-L205】
- [x] Zusätzliche Plausibilitätsprüfungen implementieren (z. B. Abgleich zwischen `takenAt` und Dateisystemzeit zur Erkennung verdächtiger Abweichungen) inklusive Index-Log-Einträgen.【F:src/Service/Metadata/TimeNormalizer.php†L153-L205】
- [x] `SolarEnricher` mit Caching für wiederkehrende Koordinaten und verbesserter Behandlung polarer Tage/Nächte ergänzen, um unnötige Neuberechnungen zu vermeiden.【F:src/Service/Metadata/SolarEnricher.php†L18-L200】【F:src/Service/Metadata/Support/SolarEventCache.php†L13-L32】【F:src/Service/Metadata/Support/SolarEventResult.php†L13-L33】
- [x] QA-Checks erweitern (z. B. Prüfung auf `timezoneOffsetMin`, `tzConfidence`) und automatisch Korrekturmaßnahmen vorschlagen.【F:src/Service/Metadata/MetadataQaInspector.php†L15-L82】

### 3. Feature-Taxonomie & Datenmodell
- [x] Das freie `features`-Array durch einen typisierten Value-Object-Ansatz ersetzen oder zumindest Namespaces/Hydration-Helper definieren, um Key-Kollisionen zu verhindern.【F:src/Service/Metadata/Feature/MediaFeatureBag.php†L1-L219】【F:src/Entity/Media.php†L585-L610】【F:src/Service/Metadata/DaypartEnricher.php†L41-L59】【F:src/Service/Metadata/FilenameKeywordExtractor.php†L30-L52】
- [ ] Feature-Versionierung (`MetadataFeatureVersion`) modularisieren, sodass pro Feature-Gruppe Migrationsroutinen definiert werden können.【F:src/Service/Metadata/MetadataFeatureVersion.php†L13-L31】
- [x] Dokumentation der Feature-Semantik (z. B. `daypart`, `holidayId`, `isGoldenHour`) erstellen und automatisiert verifizieren (Schema-Validierung in Tests).【F:docs/metadata-feature-semantics.md†L1-L120】

### 4. Video-Metadaten & Prozessausführung
- [ ] `FfprobeMetadataExtractor` auf Symfony Process bzw. asynchrone Ausführung umstellen, um Timeout/Exit-Code-Handhabung zu verbessern.【F:src/Service/Metadata/FfprobeMetadataExtractor.php†L70-L145】
- [ ] QuickTime-Auswertung mit Fallbacks für weitere Tag-Varianten (`creation_time`-Formate, Zeitzonen-Normalisierung) und Fehlertelemetrie erweitern.【F:src/Service/Metadata/FfprobeMetadataExtractor.php†L348-L479】
- [ ] Unit-Tests mit Mock-Prozessrunnern hinzufügen, die JSON-Beispiele aus Fixtures abdecken (inkl. beschädigter Payloads und Slow-Mo-Erkennung).

### 5. Inhaltliche Klassifikation & Qualitätsbewertung
- [ ] Schwellenwerte des `ContentClassifierExtractor` als konfigurierbare Parameter/DI-Argumente exponieren, um datengetriebenes Tuning zu ermöglichen.【F:src/Service/Metadata/ContentClassifierExtractor.php†L137-L248】
- [ ] Zusätzliche Feature-Quellen (z. B. Vision-Modelle) integrieren und mit Confidence-Scores kombinieren, bevor `noShow` gesetzt wird.【F:src/Service/Metadata/ContentClassifierExtractor.php†L103-L129】
- [ ] Qualitätsmetriken aus `MediaQualityAggregator` mit Zeitbezug (z. B. ISO-Schwelle abhängig vom Aufnahmedatum) versehen und Logging weiter strukturieren.【F:src/Service/Metadata/Quality/MediaQualityAggregator.php†L13-L132】

### 6. QA & Beobachtbarkeit
- [x] `MetadataQaInspector` um strukturierte Ergebnisse erweitern (statt Log-Zeilen), damit der Indexprozess maschinell reagieren kann.【F:src/Service/Metadata/MetadataQaInspector.php†L24-L78】【F:src/Service/Metadata/MetadataQaInspectionResult.php†L13-L69】【F:src/Service/Indexing/Contract/MediaIngestionContext.php†L19-L214】【F:src/Service/Indexing/Stage/TimeStage.php†L19-L87】
- [ ] Einheitliches Index-Log-Schema definieren und in allen Extractoren anwenden (aktuell schreiben nur ausgewählte Klassen aggregierte Meldungen).
- [ ] Dashboards/Reports für fehlende oder widersprüchliche Metadaten erstellen (z. B. Tageszeit ohne Zeitzone, Golden Hour ohne GPS).

### 7. Tests & Dokumentation
- [ ] Fehlende Unit-Tests für Daypart-, Solar- und Kalender-Edge-Cases ergänzen (z. B. Polartag, Zeitzonenwechsel, bewegliche Feiertage).【F:src/Service/Metadata/SolarEnricher.php†L87-L158】【F:src/Service/Metadata/CalendarFeatureEnricher.php†L67-L115】
- [ ] Integrationstests für die vollständige Pipeline aufsetzen (Fixture-Medien mit erwarteten Feature-Sets).
- [ ] Entwickler-Dokumentation aktualisieren, die neuen Konfigurationsoptionen und den erweiterten QA-Prozess beschreibt.
