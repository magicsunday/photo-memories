# Runbook: memories:feed:preview

## Zweck
- Zeigt eine tabellarische Vorschau des personalisierten Rückblick-Feeds direkt im Terminal.
- Lädt konsolidierte Cluster aus der Datenbank, führt optionale Konsolidierungs-Overrides aus und baut anschließend Feed-Karten über den `FeedBuilder`.
- Dient als schnelles Diagnose-Werkzeug für Scoring-Anpassungen, Konsolidierungslimits und Personalisierungsprofile ohne den Web-Client zu starten.

## Standardaufruf
```bash
php src/Memories.php memories:feed:preview --limit-clusters=2000
```
- `--limit-clusters` begrenzt die Anzahl der geladenen Cluster (Default: 5000). Werte <1 werden automatisch auf 1 angehoben.
- Ohne weitere Optionen nutzt der Befehl das Standard-Personalisierungsprofil aus `FeedPersonalizationProfileProvider` und die in Symfony konfigurierten Konsolidierungsstufen.

## Personalisierungs-Overrides
- `--min-score=<float>` setzt das Mindest-Scoring für Kandidaten nur für die aktuelle Ausführung. Werte müssen ≥0 sein.
- `--min-members=<int>` erzwingt eine Mindestanzahl an Mitgliedern je Kandidat (≥1). Nützlich, um große Cluster in der Vorschau zu priorisieren.
- Beide Optionen erzeugen zur Laufzeit ein Klon des Default-Profils (`<profil>-cli`) und übergeben dieses an den `FeedBuilder`. Alle übrigen Grenzwerte (max pro Tag, Qualitätsfloor usw.) bleiben unverändert.
- Ungültige Werte (z. B. negative Zahlen) führen zu `Command::INVALID` mit entsprechender Fehlermeldung.

## Konsolidierungs-Overrides
- `--per-media-cap=<int>` überschreibt temporär das Per-Media-Limit der Konsolidierung. `0` deaktiviert die Kappung, positive Werte begrenzen die Anzahl konsolidierter Stories pro Medium.
- Die Option wird vor dem Aufruf von `ClusterConsolidatorInterface::consolidate()` gesetzt und nach dem Lauf automatisch auf den Standardwert zurückgesetzt. So beeinflusst sie keine anderen Konsumenten des Konsolidators.

## Auswahl-Overrides
- Sämtliche `--sel-*` Optionen aus `SelectionOverrideInputTrait` stehen weiterhin zur Verfügung und wirken sich auf `SelectionPolicyProvider` aus (z. B. Zielmenge, Mindestabstand, pHash-Hamming).
- Mehrere Overrides können kombiniert werden. Der Befehl validiert alle Eingaben und bricht bei fehlerhaften Werten mit einer klaren Meldung ab.

## Ausgabeinterpretation
- Abschnitt „Konsolidierung“ listet jede Pipeline-Stufe mit ihrem Fortschritt auf. Ein Häkchen signalisiert, dass eine Stage abgeschlossen ist.
- Abschnitt „Feed erzeugen“ zeigt die resultierenden Karten als Tabelle mit Laufnummer (`#`), Algorithmus, Storyline, Roh- und kuratierter Mitgliederzahl, Score sowie Zeitraum. `--show-members` hängt optional eine weitere Spalte mit allen Mitglieds-IDs an.
- Erfolgreiche Läufe enden mit `✔ <n> Feed-Items angezeigt.`; leere Resultate werden als Warnung markiert.

## Fehlerbehebung
- Prüfen, ob genügend Cluster in der Datenbank vorhanden sind (`Keine Cluster ...`).
- Bei leerem Feed trotz vorhandener Cluster die kombinierten Limits prüfen (`--min-score`, `--min-members`, Konsolidierungs-Cap) und ggf. lockern.
- Konsolidierungs-Telemetrie (`JobMonitoringEmitter`) liefert zusätzliche Kontextdaten, falls aktiviert.
