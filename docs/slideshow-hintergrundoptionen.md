# Alternativen zum schwarzen Rand in Slideshows

Der aktuelle Slideshow-Generator setzt beim Einpassen von Bildern auf einen schwarzen Rand, weil der `pad`-Filter von FFmpeg explizit mit `color=black` aufgerufen wird. Für iOS-ähnliche Rückblicke lassen sich jedoch verschiedene Alternativen umsetzen.

## 1. Farbfläche mit Markenbezug
- `pad` unterstützt beliebige Hex- oder RGBA-Werte über `color=...`. Ein Beispiel wäre `pad=1920:1080:(ow-iw)/2:(oh-ih)/2:color=0x1C1C1C` für ein dunkles Grau.
- Für dynamische Akzentfarben kann vor der Filterkette ein `palettegen`/`paletteuse`-Durchlauf oder eine Durchschnittsfarb-Berechnung aus den Bildpixeln erfolgen, deren Ergebnis dann in den `pad`-Parameter injiziert wird.

## 2. Weichgezeichneter Hintergrund wie bei iOS
- Dupliziere das Bild in der Filterkette (`[0:v]split[a][b];[a]scale=1920:1080:force_original_aspect_ratio=increase,crop=1920:1080,gblur=sigma=20:enable='lt(iw/ih\,1.778)'[bg];[b]scale=1920:1080:force_original_aspect_ratio=decrease[fg];[bg][fg]overlay=(main_w-overlay_w)/2:(main_h-overlay_h)/2`).
- Das Ergebnis ist ein weichgezeichneter, vollflächiger Hintergrund. Für leichte Parallax-Effekte kann zusätzlich `zoompan` auf dem Hintergrundkanal verwendet werden.
- Diese Variante ist nun standardmäßig aktiv. Die Intensität steuerst du über den Parameter `memories.slideshow.background_blur_sigma`.

## 3. Verläufe oder Muster
- Mit `geq`, `colorchannelmixer` oder vorbereiteten PNGs lässt sich ein Farbverlauf oder Muster erzeugen (`color=c=#101820@1:s=1920x1080,format=rgba`). Anschließend den Vordergrund mit `overlay` kombinieren.
- Alternative: Ein SVG-/PNG-Asset wird per zweitem Input (`-loop 1 -i overlay.png`) eingebunden und wie im Blur-Beispiel mit `overlay` vereint.

## 4. Spiegelung der Bildkanten
- Durch `scale=iw*1.1:ih*1.1,mirror` (oder `frei0r=mirror0r`) auf einer Hintergrundkopie entstehen weich gespiegelte Kanten.
- Eine anschließende leichte Unschärfe verhindert harte Übergänge.

## Integration in den Generator
- Die Filterketten werden in `buildSingleImageCommand()` und `buildMultiImageCommand()` erzeugt. Dort kann man die Hintergründe anpassen oder zusätzliche Inputs registrieren.
- Die Blur-Stärke des Standard-Hintergrunds lässt sich in `config/parameters.yaml` über `memories.slideshow.background_blur_sigma` konfigurieren.
- Für konfigurierbare Hintergründe empfiehlt sich ein neuer Parameter wie `memories.slideshow.background` in `config/parameters.yaml`, der anschließend via DI in den `SlideshowVideoGenerator` injiziert wird.
- Der animierte Ken-Burns-Effekt ist standardmäßig aktiv und lässt sich über `memories.slideshow.ken_burns_enabled` deaktivieren. Die Zoom-Faktoren (`memories.slideshow.zoom_start`, `memories.slideshow.zoom_end`) sollten sich typischerweise im Bereich 1.0–1.25 bewegen, damit Bilddetails erhalten bleiben. Horizontale und vertikale Verschiebungen steuerst du mit `memories.slideshow.pan_x` bzw. `memories.slideshow.pan_y` im Bereich -1.0 bis 1.0, wobei 0 dem zentrierten Bild entspricht und der Standardwert von 0.15 für eine leichte Bewegung sorgt.

Mit diesen Ansätzen lassen sich schwarze Balken vermeiden und das Erscheinungsbild stärker an Plattformen wie iOS anlehnen.
