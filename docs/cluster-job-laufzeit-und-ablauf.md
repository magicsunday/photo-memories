# Laufzeitanalyse und Ablauf des Cluster-Jobs

## Änderung mit Einfluss auf die Dauer

Der Cluster-Lauf enthält seit der Integration des `CompositeClusterScorer` eine zusätzliche Bewertungsphase. Nachdem alle Strategien ihre Entwürfe geliefert haben, ruft der `HybridClusterer` nun `scorer->score()` auf und versieht jeden Entwurf mit Titel und Untertitel. Dadurch werden neue Heuristiken ausgeführt, bevor die Konsolidierung startet.

Diese Bewertungsphase zieht sämtliche Medien-IDs aus den Entwürfen, lädt die zugehörigen `Media`-Objekte paketweise erneut aus der Datenbank und lässt anschließend jede registrierte Heuristik die Cluster anreichern. Die zusätzliche Datenbankarbeit und die Berechnungen der Heuristiken erhöhen die Laufzeit signifikant, insbesondere bei großen Medienmengen.

## Ablauf des Cluster-Jobs

1. **Medien laden:** Der `DefaultClusterJobRunner` baut zwei Doctrine-Queries auf, zählt die Treffer und streamt anschließend alle passenden `Media`-Objekte in Speicher. Fortschritt und Durchsatz werden dabei über den `ProgressReporter` visualisiert.
2. **Feature-Version prüfen:** Direkt nach dem Laden wird kontrolliert, ob alle Medien die aktuelle Feature-Version besitzen. Abweichungen führen zu einer Warnung, damit vor dem Clustering ein erneuter Indexlauf durchgeführt wird.
3. **Strategien ausführen:** Der `HybridClusterer` iteriert über alle registrierten Strategien, sammelt deren Entwürfe und meldet Fortschritt an den Reporter. Dieses Stadium endet mit einer Liste an `ClusterDraft`-Objekten.
4. **Bewerten und anreichern:** In der neuen Bewertungsphase lädt der `CompositeClusterScorer` alle beteiligten Medien nach und führt die konfigurierten Heuristiken aus, um Score-Werte und Zusatzparameter (z. B. Qualität, Personen, Ort) zu berechnen. Anschließend werden die Cluster nach Score sortiert. Während dieser Schritte blendet der CLI-Reporter nun einen eigenen Fortschrittsbalken „🏅 Score & Titel“ ein, der das Laden der Mediendaten, die Heuristik-Läufe sowie die anschließende Titelgenerierung transparent macht.
5. **Konsolidieren:** Die entstandenen Entwürfe laufen durch mehrere Konsolidierungsstufen, die Überlappungen auflösen, Mindestgrößen prüfen und Konflikte anhand konfigurierter Prioritäten bereinigen.
6. **Persistenz vorbereiten:** Optional werden bei aktivierter `--replace`-Option die bisherigen Cluster der betroffenen Algorithmen vor dem Speichern gelöscht.
7. **Speichern oder Trockenlauf:** Abhängig von den Optionen werden die finalen Cluster entweder im Trockenlauf gezählt oder in Batches persistiert. Fortschritt und Durchsatz werden erneut angezeigt.

Durch diese Schritte erhält der Cluster-Lauf konsistente, bewertete Ergebnisse – gleichzeitig erklärt die zusätzliche Bewertungsphase den spürbaren Anstieg der Gesamtdauer.
