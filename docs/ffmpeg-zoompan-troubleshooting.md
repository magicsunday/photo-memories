# Fehlerbehebung: FFmpeg meldet "No option name near 'if(gte(iw/ih'"

## Problemstellung
Bei der Erstellung eines Videos mit `ffmpeg` bricht der Lauf mit folgender Meldung ab:

```
[AVFilterGraph @ ...] No option name near 'if(gte(iw/ih'
[AVFilterGraph @ ...] Error parsing a filter description around: ,1.778),clip(...
...
Error parsing global options: Invalid argument
```

Der Filtergraph enthält verschachtelte `if()`-, `min()`-, `max()`- oder `clip()`-Ausdrücke mit Kommata. `ffmpeg` interpretiert jedes unveränderte Komma als Trenner zwischen Filteroptionen. Dadurch zerfällt die Definition des `zoompan`-Filters und der Parser erwartet nach dem Komma einen neuen Optionsnamen.

## Lösungsschritte
1. **Kommata escapen**  
   Innerhalb von Ausdrücken müssen alle Kommata mit einem Backslash (`\,`) maskiert werden. Beispiel:
   ```
   zoompan=z=if(gte(iw/ih\,1.778)\,1.05+(1.15-1.05)*min(t/4.3\,1)\,1):
           x=if(gte(iw/ih\,1.778)\,clip(...)):
           y=if(...)
   ```
   Erst dadurch erkennt der Parser, dass die Kommata zu den Funktionen gehören.

2. **Zeitvariable wählen**  
   In `zoompan` ist `PTS` unpraktisch, weil es den Präsentationszeitstempel der Pipeline repräsentiert. Verwende stattdessen `t` (Sekunden) oder `on` (Frame-Zähler) für reproduzierbare Animationen:
   ```
   min(t/4.3\, 1)
   ```

3. **Fehlerstellen prüfen**  
   Iteriere über jede Filterstufe (`[fg0]`, `[fg1]`, ...), kontrolliere die Ausdrücke und stelle sicher, dass dort keine unbeabsichtigten Kommata verbleiben.

## Zusammenfassung
- FFmpeg trennt Optionen an Kommata.
- Maskiere Kommata in Funktionsaufrufen mit `\,`.
- Nutze `t` oder `on` im `zoompan`-Filter anstelle von `PTS`.

Nach diesen Anpassungen lässt sich der Filtergraph fehlerfrei parsen und das Video rendert wie erwartet.
