# Aufgabenliste für iOS-/Google-ähnliche Rückblicke

## 1. Personalisierung und Ranking schärfen
- [x] Score-Heuristiken kalibrieren und fehlende Qualitäts- sowie Personenmetriken ergänzen: `MemoryFeedBuilder` gewichtet Qualitäts- und Personenkennzahlen nun anhand personalisierbarer Profile, berücksichtigt Recency-Boni sowie Stale-Abschläge und markiert Cluster mit dem aktiven Profil.【F:src/Service/Feed/MemoryFeedBuilder.php†L31-L214】
- [x] Feedback- und Favoriten-Tracking konzipieren, inklusive persistenter Nutzerprofile und Opt-out-Logik: Die neue `FeedUserPreferenceStorage` legt Favoriten und Opt-out-Algorithmen je Nutzer und Profil im JSON-Backend ab, während der `FeedController` Filterung, Metadaten und Favoriten-Markierung übernimmt.【F:src/Service/Feed/FeedUserPreferenceStorage.php†L17-L184】【F:src/Http/Controller/FeedController.php†L118-L215】
- [x] Parameter für personalisierte Schwellenwerte in `config/parameters.yaml` vorbereiten: Gewichtungen, Score-Schwellen und Profilkatalog sind zentral konfigurierbar und werden vom `FeedPersonalizationProfileProvider` an den Feed übergeben.【F:config/parameters.yaml†L233-L282】【F:config/services.yaml†L1044-L1075】【F:src/Service/Feed/FeedPersonalizationProfileProvider.php†L17-L86】

## 2. Storytelling-Erlebnis aufwerten
- [x] Storyboards aus dem JSON-Feed ableiten und in Slideshow-Generierung sowie UI integrieren: `FeedController` liefert jetzt einen `storyboard`-Block mit Folieninformationen, Übergängen, Dauer- und Kontextangaben, während `SlideshowVideoManager` und `SlideshowVideoGenerator` dieselben Daten für die Videoerstellung nutzen.【F:src/Http/Controller/FeedController.php†L312-L392】【F:src/Service/Slideshow/SlideshowVideoManager.php†L41-L139】【F:src/Service/Slideshow/SlideshowVideoGenerator.php†L39-L269】
- [x] Konfigurierbare Musik-, Übergangs- und Dauerparameter definieren: Sämtliche Werte werden nun über `config/parameters.yaml` gesteuert und in Services injiziert; FFMPEG greift optional auf eine konfigurierte Audiodatei zu.【F:config/parameters.yaml†L306-L316】【F:config/services.yaml†L90-L118】【F:src/Service/Slideshow/SlideshowVideoGenerator.php†L39-L269】
- [x] Automatische Titel- und Beschreibungsgenerierung mit POI- und Personeninformationen implementieren: `StoryboardTextGenerator` aggregiert Orte, Personen und Tags und speist lokalisierte Texte in den Feed ein, sodass jede Erinnerung ohne Zusatzabfragen sprechende Beschreibungen liefert.【F:src/Service/Feed/StoryboardTextGenerator.php†L17-L255】【F:src/Http/Controller/FeedController.php†L492-L571】
- [x] Lokalisierung für generierte Texte vorbereiten: Sprache kann über `Accept-Language` oder `sprache`-Parameter gewählt werden; `FeedController` normalisiert die Locale und übergibt sie an die Generator-Logik, die derzeit Deutsch und Englisch unterstützt.【F:src/Http/Controller/FeedController.php†L196-L210】【F:src/Http/Controller/FeedController.php†L347-L386】【F:src/Service/Feed/StoryboardTextGenerator.php†L91-L143】

## 3. Mobile-first Frontend neu denken
- Komponentenbasierte SPA (z. B. Vue oder React) mit Timeline, „Für dich“-Feed und Story-Viewer entwerfen.
- Touch-Gesten, Animationen und Offline-PWA-Merkmale planen.
- API-Erweiterungen für Lazy Loading, Paging und personalisierte Filter spezifizieren.

## 4. Kontext- und Discovery-Features ausbauen
- Ort- und Reiseerkennung um Wegpunkte und öffentliche Events erweitern.
- Serien von Erinnerungen über mehrere Jahre hinweg hervorheben.
- Benachrichtigungs- und Planungslogik (z. B. „On this day“) entwerfen.

## 5. Qualitätssicherung und Betrieb stärken
- CI/CD-Pipelines mit `composer ci:test` und `npm run test:e2e` automatisieren.
- Parameter in `config/parameters.yaml` modularisieren und dokumentieren.
- Monitoring für Geocoding- und Video-Jobs vorbereiten.
