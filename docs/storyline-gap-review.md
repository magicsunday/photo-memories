# Storyline Feature Gap Review

## Verified scope
- Die neue `StaypointStage` reichert Tageszusammenfassungen um den `StaypointIndex`, die Staypoint-Häufigkeiten sowie Transit- und POI-Kennzahlen an und legt damit die Basis für die gewünschte Storyline-Auswertung.【F:src/Clusterer/DaySummaryStage/StaypointStage.php†L43-L114】
- Die kanonische Titelstufe setzt bereits algorithmische Titel und Untertitel für Urlaubs-Cluster und greift dabei auf existierende Aufenthalts- und Datumsparameter zu.【F:src/Service/Clusterer/Pipeline/CanonicalTitleStage.php†L49-L258】

## Offene Aufgaben
1. **Explizite Storyline-Metriken ergänzen.** Aktuell schreibt die `StaypointStage` nur die einzelnen `staypointCounts`, aber kein aggregiertes `staypoint_count` bzw. ähnliche Kennzahlen („#Staypoints/Tag“), die im Pflichtenheft gefordert sind. Ergänze die Tageszusammenfassung um solche aggregierten Felder und passe Folgeprozesse (Segment-Assembler, Telemetrie) an.【F:src/Clusterer/DaySummaryStage/StaypointStage.php†L43-L61】
2. **Kanonische Routenbetitelung gemäß Vorgabe ausbauen.** Die `CanonicalTitleStage` leitet den Titel weiterhin nur aus Primär-Staypoint-Strings ab und nutzt keine Stopplisten oder Distanzen; außerdem wird ein Bindestrich statt des gewünschten `→`-Formats erzeugt und der Untertitel enthält keine Distanz-/Etappeninformationen. Integriere hier die vorhandene Routenlogik (`travel_waypoints`/`RouteSummarizer`), um Titel wie „City A → City B → City C“ sowie metrische Untertitel (Distanz, Etappen) zu generieren.【F:src/Service/Clusterer/Pipeline/CanonicalTitleStage.php†L87-L125】
3. **Storyline-spezifische Parameter sichtbar machen.** Im Code findet sich keine explizite „storyline“-Parameterisierung, sodass weder Auswahllogik noch Scoring derzeit einen Storyline-Key berücksichtigen. Plane einen minimalen Parameter (z. B. `storyline_id`/`storyline_type`) in den relevanten Pipelines, damit kommende Stages Storylines differenziert behandeln können.【a9e8ca†L1-L2】
