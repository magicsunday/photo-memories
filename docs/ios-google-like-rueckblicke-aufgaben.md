# Aufgabenliste für iOS-/Google-ähnliche Rückblicke

## 1. Personalisierung und Ranking schärfen
- Score-Heuristiken kalibrieren und fehlende Qualitäts- sowie Personenmetriken ergänzen.
- Feedback- und Favoriten-Tracking konzipieren, inklusive persistenter Nutzerprofile und Opt-out-Logik.
- Parameter für personalisierte Schwellenwerte in `config/parameters.yaml` vorbereiten.

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
