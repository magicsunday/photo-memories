# Migration: Roh-/Kurations-Overlay für Cluster

## Zielsetzung
Diese Migration aktualisiert alle bestehenden Erinnerungs-Cluster, damit die
neue Trennung zwischen rohen Mitgliedern und kuratiertem Overlay konsistent in
den gespeicherten Parametern ankommt. Der Roh-Bestand (`members`) bleibt
unverändert, während `member_quality.summary` und Telemetrie um das
Kurations-Overlay ergänzt werden. Die Schritte gelten für Produktions- und
Staging-Umgebungen gleichermaßen.

## Voraussetzungen
- Aktuelle Anwendungsversion mit dem Kommando
  `memories:cluster:migrate-curation` ist ausgerollt.
- Backups der Cluster-Tabelle liegen vor (z. B. MySQL Dump der Tabelle
  `cluster`).
- Wartungsfenster ist kommuniziert; der Vorgang sperrt die Tabelle während der
  Transaktion.
- PHP CLI ist verfügbar (`bin/console`), alle Abhängigkeiten wurden mit
  `composer install --no-dev` installiert.

## Ablauf
1. **Dry-Run durchführen** – prüft Datenbestand ohne Änderungen:
   ```bash
   bin/console memories:cluster:migrate-curation --dry-run --batch-size=200
   ```
   Optional lässt sich die Stichprobe auf bestimmte Algorithmen begrenzen:
   ```bash
   bin/console memories:cluster:migrate-curation --dry-run --algorithm=vacation
   ```
   Die Ausgabe zeigt Roh- vs. Kurations-Mitglieder sowie Overlay-Zählung.
2. **Dry-Run prüfen** – Log auf Fehlermeldungen kontrollieren. Bei Success kann
   der echte Lauf gestartet werden.
3. **Migration ausführen** – läuft vollständig in einer Transaktion und ist bei
   Fehlern atomar:
   ```bash
   bin/console memories:cluster:migrate-curation --batch-size=200
   ```
   Größere Installationen können `--batch-size` anpassen (Faustregel: 1–5 % des
   Gesamtbestands pro Flush).
4. **Ergebnis sichern** – Command-Ausgabe dokumentieren und im Wartungsprotokoll
   ablegen.

## Verifikation
1. **Command-Zusammenfassung** – Die Tabelle am Ende sollte `Mitglieder (roh)`
   und `Kuratiertes Overlay` mit plausiblen Werten zeigen (Overlay ≥ 0,
   kuratiert ≤ roh).
2. **Datenbank-Spotchecks** – Beispielsweise für 14-tägige Urlaube prüfen, dass
   Kurationszahlen gesetzt wurden:
   ```bash
   bin/console doctrine:query:sql "
     SELECT
       id,
       TIMESTAMPDIFF(DAY, startAt, endAt) + 1 AS duration_days,
       JSON_UNQUOTE(JSON_EXTRACT(params, '$.member_quality.summary.selection_counts.raw')) AS raw_count,
       JSON_UNQUOTE(JSON_EXTRACT(params, '$.member_quality.summary.selection_counts.curated')) AS curated_count,
       JSON_UNQUOTE(JSON_EXTRACT(params, '$.member_quality.summary.curated_overlay_count')) AS overlay_count
     FROM cluster
     WHERE algorithm = 'vacation'
       AND TIMESTAMPDIFF(DAY, startAt, endAt) >= 13
     ORDER BY startAt DESC
     LIMIT 5;
   "
   ```
   Erwartet wird `curated_count ≤ raw_count` und `overlay_count ≥ curated_count`.
3. **Feed-Vorschau** – Mitgliederlisten stichprobenartig prüfen (insbesondere
   lange Urlaube mit vielen Bildern):
   ```bash
   bin/console memories:feed:preview --show-members --min-members=40 --limit-clusters=50
   ```
   Die Anzeige `Mitglieder (kuratiert)` sollte nun den Overlay-Wert reflektieren.
4. **Anwendungs-Spotchecks** – In der Oberfläche stichprobenartig mindestens
   einen 14-Tages-Urlaub und zwei reguläre Cluster öffnen und sicherstellen, dass
   Highlights reduziert, aber Rohmitglieder weiterhin erreichbar sind.

## Troubleshooting
- **Dry-Run bricht ab:** Fehler analysieren, ggf. betroffene Strategie (Algorithmus)
  mit `--algorithm=<name>` isolieren und erneut testen.
- **Migration stoppt:** Keine Daten wurden geschrieben, da die Transaktion
  zurückgerollt wird. Ursache beheben und Command ohne `--dry-run` erneut
  starten.
- **Overlay-Zähler fehlt:** Prüfen, ob Metadaten-Lookup (`memories:index`) aktuell
  ist; veraltete Medien vorab reindizieren.

## Kommunikation
- Ergebnisse (Dry-Run + produktiver Lauf) im Wartungsprotokoll dokumentieren.
- Hinweis an Support-Team: neue Kurationszahlen sind verfügbar, Rohlisten bleiben
  unverändert.
