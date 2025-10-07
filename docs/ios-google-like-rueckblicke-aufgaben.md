# Aufgabenliste für iOS-/Google-ähnliche Rückblicke

## 1. Personalisierung und Ranking schärfen
- [x] Score-Heuristiken kalibrieren und fehlende Qualitäts- sowie Personenmetriken ergänzen: `MemoryFeedBuilder` gewichtet Qualitäts- und Personenkennzahlen nun anhand personalisierbarer Profile, berücksichtigt Recency-Boni sowie Stale-Abschläge und markiert Cluster mit dem aktiven Profil.【F:src/Service/Feed/MemoryFeedBuilder.php†L31-L214】
- [x] Feedback- und Favoriten-Tracking konzipieren, inklusive persistenter Nutzerprofile und Opt-out-Logik: Die neue `FeedUserPreferenceStorage` legt Favoriten und Opt-out-Algorithmen je Nutzer und Profil im JSON-Backend ab, während der `FeedController` Filterung, Metadaten und Favoriten-Markierung übernimmt.【F:src/Service/Feed/FeedUserPreferenceStorage.php†L17-L184】【F:src/Http/Controller/FeedController.php†L118-L215】
- [x] Parameter für personalisierte Schwellenwerte in `config/parameters.yaml` vorbereiten: Gewichtungen, Score-Schwellen und Profilkatalog sind zentral konfigurierbar und werden vom `FeedPersonalizationProfileProvider` an den Feed übergeben.【F:config/parameters.yaml†L233-L282】【F:config/services.yaml†L1044-L1075】【F:src/Service/Feed/FeedPersonalizationProfileProvider.php†L17-L86】

## 2. Storytelling-Erlebnis aufwerten
- Storyboards aus dem JSON-Feed ableiten und in Slideshow-Generierung sowie UI integrieren.
- Konfigurierbare Musik-, Übergangs- und Dauerparameter definieren.
- Automatische Titel- und Beschreibungsgenerierung mit POI- und Personeninformationen implementieren.
- Lokalisierung für generierte Texte vorbereiten.

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
