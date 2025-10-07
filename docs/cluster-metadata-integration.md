# Nutzung der Metadaten in den Cluster-Strategien

## Bestandsaufnahme der vorhandenen Metadaten
Die `Media`-Entität persistiert weit mehr als nur Zeit- und GPS-Informationen. Neben Aufnahmezeit und -ort stehen Gerätemerkmale (Kamera, Objektiv, Seriennummern), Qualitätsmetriken (Schärfe, ISO, Belichtung), Szenenklassifizierungen, Schlagwörter sowie Gesichts- und Personenlisten zur Verfügung.【F:src/Entity/Media.php†L200-L320】【F:src/Entity/Media.php†L400-L520】【F:src/Entity/Media.php†L2145-L2300】 Ergänzend liefern verknüpfte `Location`-Objekte strukturierte Adress- und POI-Informationen, die bereits normalisiert in der Datenbank vorliegen.【F:src/Entity/Location.php†L17-L146】

## Aktuelle Nutzung pro Strategie

Die folgende Übersicht fasst zusammen, welche Metadaten bereits produktiv zum Einsatz kommen und wo zusätzlicher Nutzen gehoben werden kann. Damit lassen sich Aufwände präzise planen und Verantwortlichkeiten an die betroffenen Quellmodule koppeln.

| Strategie | Bereits genutzte Metadaten | Potenzial / fehlende Nutzung |
| --- | --- | --- |
| **TimeSimilarityStrategy** | Aufnahmezeit (inkl. lokaler Tagesgrenzen), Ortslabels, dominante Schlagwörter/Szenentags.【F:src/Clusterer/TimeSimilarityStrategy.php†L55-L126】 | Personen- und Qualitätsmetriken fehlen, obwohl sie für Serienevents hilfreich wären. |
| **BurstClusterStrategy** | Zeit- & Ortskontext, Burst-IDs/-Repräsentanten, dominante Tags.【F:src/Clusterer/BurstClusterStrategy.php†L69-L165】 | Kein aggregierter Personenüberblick trotz Serienfokus. |
| **DeviceSimilarityStrategy** | Gerätedaten (Kamera/Lens/Owner), Content-Klassifizierung, Tagesbuckets.【F:src/Clusterer/DeviceSimilarityStrategy.php†L60-L232】 | Tags, Personen- und Qualitätswerte werden ignoriert. |
| **PortraitOrientationClusterStrategy** | Zeitfilter, Hochkantflag, Gesichtsanwesenheit.【F:src/Clusterer/PortraitOrientationClusterStrategy.php†L58-L151】 | Orts- und Tag-Anreicherungen fehlen, wodurch Cluster schlecht beschreibbar sind. |
| **TransitTravelDayClusterStrategy** | GPS-Tagespfade, Distanz, Ortslabel, Away-Metriken.【F:src/Clusterer/TransitTravelDayClusterStrategy.php†L31-L226】 | Geschwindigkeit/Heading aus `Media` wird nicht zur Absicherung genutzt. |
| **MonthlyHighlightsClusterStrategy** | Monatsbuckets, dominante Tags, Ortscluster.【F:src/Clusterer/MonthlyHighlightsClusterStrategy.php†L42-L140】 | Geräte-, Personen- und Qualitätsaggregation fehlt. |
| **NightlifeEventClusterStrategy** | Tageszeit/Daypart, POI-Kontext, Tags, Ortslabel.【F:src/Clusterer/NightlifeEventClusterStrategy.php†L33-L210】 | Keine aggregierten Personen- oder Qualitätsmetriken. |
| **PersonCohortClusterStrategy** | Personenlisten, Kohortenbildung nach Signaturen.【F:src/Clusterer/PersonCohortClusterStrategy.php†L35-L214】 | Es fehlen Orts- und Tagzusammenfassungen für UI/Scoring. |
| **Zeit- & Feiertagsstrategien** (`HolidayEvent`, `Anniversary`, `Season`, `OnThisDay`, …) | Aufnahmezeiten, Kalenderfeatures, Orts- und POI-Daten.【F:src/Clusterer/HolidayEventClusterStrategy.php†L33-L179】【F:src/Clusterer/AnniversaryClusterStrategy.php†L29-L153】 | Qualitäts- und Personenmetriken werden nicht erhoben, obwohl sie für Ranking & Feed hilfreich wären. |

## Aufgabenplan zur besseren Metadatennutzung

Die folgenden Arbeitspakete zeigen, welche Anpassungen nötig sind, um den Nutzen der vorhandenen Metadaten vollständig auszuschöpfen. Jede Aufgabe verweist auf die betroffenen Quellmodule.

### Paket A – Personenmetriken konsequent erheben
- [ ] **Gemeinsame Personenaggregation implementieren:** Einen dedizierten Helfer (z. B. `ClusterPeopleAggregator`) analog zum Qualitätsaggregator ergänzen, der eindeutige Personen, Gesichtsbedeckung und Coverage berechnet. Den Helfer in `ClusterBuildHelperTrait` verfügbar machen und in `BurstClusterStrategy`, `DeviceSimilarityStrategy`, `MonthlyHighlightsClusterStrategy`, `PortraitOrientationClusterStrategy` sowie `NightlifeEventClusterStrategy` einsetzen, um `people_*`-Parameter direkt beim Draft zu setzen.【F:src/Clusterer/Support/ClusterBuildHelperTrait.php†L57-L187】【F:src/Service/Clusterer/Scoring/PeopleClusterScoreHeuristic.php†L19-L84】
- [ ] **PersonCohort-Cluster beschreiben:** Nach Erstellung des Drafts `collectDominantTags()` und `applyLocationMetadata()` aus dem Helper/Trait aufrufen, damit die Cohort-Cluster neben Personen auch Orts- und Schlagwortinformationen enthalten.【F:src/Clusterer/PersonCohortClusterStrategy.php†L138-L214】【F:src/Clusterer/Support/ClusterLocationMetadataTrait.php†L27-L83】

### Paket B – Inhalts- und Kontextdaten ergänzen
- [ ] **Portrait-Cluster anreichern:** In `PortraitOrientationClusterStrategy` nach dem Zeit-Bucketing `collectDominantTags()` nutzen und die Ortsanreicherung aus `ClusterLocationMetadataTrait` übernehmen, damit Porträtserien mit Schlagworten, Szenen und Ortskomponenten beschrieben werden.【F:src/Clusterer/PortraitOrientationClusterStrategy.php†L136-L147】【F:src/Clusterer/Support/ClusterLocationMetadataTrait.php†L27-L83】
- [ ] **Device-Cluster mit Inhaltskontext erweitern:** `DeviceSimilarityStrategy` soll zusätzlich `collectDominantTags()` und den neuen Personenaggregator einsetzen, um wiederkehrende Motive des Geräts sichtbar zu machen. Bei neu eingeführten Parametern sicherstellen, dass `ClusterPersistenceService::buildMetadata()` sie übernimmt oder bewusst ignoriert.【F:src/Clusterer/DeviceSimilarityStrategy.php†L99-L124】【F:src/Service/Clusterer/ClusterPersistenceService.php†L264-L305】
- [ ] **Nightlife- und Saison-Cluster erweitern:** Nach dem Zusammenstellen der Cluster `ClusterQualityAggregator` und den Personenaggregator anwenden, damit `NightlifeEventClusterStrategy` und Feiertags-/Saison-Strategien Qualitäts- und Personenwerte persistieren.【F:src/Clusterer/NightlifeEventClusterStrategy.php†L175-L210】【F:src/Clusterer/HolidayEventClusterStrategy.php†L126-L179】【F:src/Clusterer/Support/ClusterQualityAggregator.php†L17-L109】

### Paket C – Bewegungs- und Qualitätsmetriken einbeziehen
- [ ] **Reise-Cluster mit Bewegungsmetriken absichern:** In `TransitTravelDayClusterStrategy` zusätzlich die vorhandenen GPS-Geschwindigkeiten und Heading-Werte aus `Media` nutzen, um Tage mit überwiegender Fortbewegung robuster zu erkennen (z. B. Mindestanzahl schneller Segmente) und diese Werte in den Clusterparametern abzulegen.【F:src/Clusterer/TransitTravelDayClusterStrategy.php†L55-L226】【F:src/Entity/Media.php†L282-L303】
- [ ] **Monatshighlights um Qualitätswerte erweitern:** Nach Auswahl der Monatsliste den Qualitätsaggregator (`ClusterQualityAggregator`) sowie den Personenaggregator einsetzen, um `quality_*`- und `people_*`-Parameter direkt im Draft zu speichern. Diese Werte anschließend beim Persistieren berücksichtigen.【F:src/Clusterer/MonthlyHighlightsClusterStrategy.php†L81-L140】【F:src/Clusterer/Support/ClusterQualityAggregator.php†L17-L109】【F:src/Service/Clusterer/ClusterPersistenceService.php†L264-L305】

### Paket D – Persistenz & Nachverarbeitung absichern
- [ ] **Persistierte Parameter prüfen:** In `ClusterPersistenceService::buildMetadata()` sowie `ClusterEntityToDraftMapper` gezielt testen, ob neue Parameter (Personen, Bewegungsmetriken, Qualitätswerte) serialisiert und wiederhergestellt werden, damit Folgeheuristiken (Scoring, Feed) auf sie zugreifen können.【F:src/Service/Clusterer/ClusterPersistenceService.php†L264-L343】【F:src/Support/ClusterEntityToDraftMapper.php†L46-L103】
- [ ] **Scoring-Heuristiken validieren:** Die Heuristiken für Personen-, POI-, Qualitäts- und Recency-Bewertungen (`PeopleClusterScoreHeuristic`, `PoiClusterScoreHeuristic`, `QualityClusterScoreHeuristic`, `RecencyClusterScoreHeuristic`) in Unit-Tests gegen die neuen Parameter laufen lassen und bei Bedarf die Gewichtung anpassen.【F:test/Unit/Service/Clusterer/Scoring/PeopleClusterScoreHeuristicTest.php†L19-L84】【F:test/Unit/Service/Clusterer/Scoring/CompositeClusterScorerTest.php†L62-L119】

Mit diesen Anpassungen spiegeln die Cluster-Ergebnisse die reichhaltigen Metadaten der Bibliothek deutlich besser wider und schaffen eine belastbarere Grundlage für Scoring, Ranking und UI-Darstellung.
