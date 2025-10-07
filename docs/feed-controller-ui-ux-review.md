# Bewertung der FeedController-Ausgabe

Dieser Bericht bewertet die JSON-Antwort des `FeedController` und skizziert UI/UX-Verbesserungen, die Frontend-Clients bei der Darstellung des Rückblick-Feeds unterstützen können.

## Aktuelle Struktur
- **Metadaten (`meta`)** enthalten das Erstellungsdatum, Gesamt- und Liefermengen, verfügbare Strategien/Gruppen sowie die angewandten Filter. Zeit- und Datumswerte werden im ISO-8601-Format (`DateTimeInterface::ATOM`) ausgegeben.【F:src/Http/Controller/FeedController.php†L94-L139】【F:src/Http/Controller/FeedController.php†L248-L273】
- **Feed-Elemente (`items`)** liefern technische Kennungen, Algorithmus, Gruppenzuordnung, Titel, Untertitel, Score, Cover-Medieninformationen, Mitglieds-IDs, Vorschaudaten, Zeitspanne und Roh-Parameter. Slideshow-Informationen werden als vom Manager generiertes Status-Array zurückgegeben.【F:src/Http/Controller/FeedController.php†L274-L354】
- **Galerieeinträge** enthalten derzeit nur IDs, Thumbnail-URLs und optionale Aufnahmedaten, jedoch keine Deskriptoren wie Personen, Orte oder Beschreibungen.【F:src/Http/Controller/FeedController.php†L306-L333】

## Beobachtete UX-Herausforderungen
1. **Hohe technische Dichte**: Feldnamen wie `strategie`, `score` oder `zeitspanne` sind wenig selbsterklärend für Endnutzer:innen und verlangen zusätzliche Mapping-Logik im Frontend.
2. **Fehlende menschenlesbare Zeitangaben**: Datum und Zeit werden ISO-konform, aber nicht lokalisiert bereitgestellt. Für eine unmittelbare Wahrnehmung fehlen relative Angaben („vor 3 Tagen“) oder formatierte Datumsstrings.
3. **Unvollständige Galerie-Kontexte**: Galerieeinträge liefern keine ergänzenden Informationen (z. B. Ort, Personen, Schlagwörter). Ohne weitere API-Aufrufe ist ein reichhaltiges UI schwierig.
4. **Slideshow-Status ohne Kontext**: Das Status-Array der Slideshow enthält keine Texte oder Fortschrittsinformationen, die direkt an Nutzer:innen kommuniziert werden könnten.
5. **Fehlende Pagination-Hinweise**: Zwar existiert `limit`, doch Informationen über Folgeseiten oder Cursor-Werte fehlen, was bei längeren Feeds zu unklarer Navigation führt.

## Empfohlene Verbesserungen
### 1. Benutzerfreundliche Feldbenennung und Tooltips
- Frontend-seitig sprechende Labels einführen („Strategie“ → „Entdeckungsmodus“, „Score“ → „Relevanzwert“).
- Optional ergänzende Metadaten im Backend bereitstellen (`meta.labelMapping`), damit Clients standardisierte Beschreibungen anzeigen können.

### 2. Erweiterte Zeitdarstellung
- Ergänzend zu den ISO-Stempeln vorformatierte Strings liefern (z. B. `meta.hinweisErstelltAm`: „aktualisiert vor 2 Stunden“, `galerie[].aufgenommenAmText`: „12. März 2023, 14:35“).
- Für Zeitspannen Start- und Enddaten klar benennen (`zeitspanne.vonText`, `zeitspanne.bisText`) sowie relative Angaben (z. B. „Sommer 2018“ basierend auf Heuristiken).

### 3. Kontextreichere Galerie-Einträge
- Bereits bekannte Entitäten (Personen, Orte, Ereignisse) in `galerie` integrieren, damit Kacheln Kontext und Filterchips erhalten.
- Alt-Texte oder Beschreibungen bereitstellen, um Barrierefreiheit zu fördern und Skeleton-Loader zu vermeiden.

### 4. Slideshow-Kommunikation verbessern
- Slideshow-Status um deklarative Felder erweitern (z. B. `status`, `progress`, `hint`), um im UI sinnvolle CTAs oder Fortschrittsanzeigen zu ermöglichen.
- Fehlerzustände mit klaren Codes und Übersetzungs-Schlüsseln versehen, damit Clients verständliche Meldungen rendern können.

### 5. Navigations- und Ladefeedback
- Pagination- oder Cursor-Informationen im `meta`-Block ergänzen (`nextCursor`, `hasMore`), um Endless-Scroll oder Paginierung zu unterstützen.
- Optional „Empfohlenes Limit“ oder „Verfügbare Gesamtseiten“ ausgeben, damit Clients Ladeindikatoren planen.

### 6. Konsistente Medien-URLs
- Absolute URLs (inkl. Host) bereitstellen, um Cross-Origin- oder CDN-Szenarien zu erleichtern.
- Vorschaubilder durch Parameter wie `aspectRatio`, `width`, `height` ergänzen, damit Layout-Sprung-Effekte reduziert werden.

## Weiterführende Überlegungen
- Eine dedizierte Übersetzungs- oder Hilfetabelle im Backend erleichtert lokalen UIs den Umgang mit fachlichen Begriffen.
- Ein GraphQL- oder konfigurierbares REST-Endpunkt-Design könnte Clients erlauben, nur benötigte Felder anzufragen und so Antwortgröße sowie Parsing-Aufwand zu reduzieren.
- Für responsive Layouts empfiehlt sich ein Feld, das auf verfügbare Alternative-Formate (z. B. quadratisch, Panoramabild) hinweist.
