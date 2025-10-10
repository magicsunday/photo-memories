# Locating Fonts in Minimal Linux Containers

When FFmpeg overlays text via `drawtext`, it must reference a font that the container can resolve. Minimal base images often lack the usual font configuration helpers, so the following checklist helps you discover and register fonts when you hit an error such as `Cannot find a valid font for the family DejaVu Sans`.

## 1. Inspect the Current Font Cache

Most Debian/Ubuntu-derived images ship with `fontconfig`. If it is available, list all registered fonts:

```bash
fc-list | head
```

Search for a specific family (for example DejaVu):

```bash
fc-list | grep -i 'dejavu'
```

To see the full path FFmpeg should use when `font=` is not enough, ask fontconfig for the matching file:

```bash
fc-match -v 'DejaVu Sans' | grep file
```

## 2. Scan Common Font Directories

If `fontconfig` is missing, inspect the standard font directories manually:

- `/usr/share/fonts/`
- `/usr/local/share/fonts/`
- `~/.fonts/`

For example, to list TrueType files under `/usr/share/fonts`:

```bash
find /usr/share/fonts -type f \( -name '*.ttf' -o -name '*.otf' \)
```

> **Tip:** Keep the search scoped to known font directories to avoid slow walks across the entire filesystem.

## 3. Install the Required Font

If the font is absent, add it to the image. For DejaVu on Debian-based images:

```bash
apt-get update && apt-get install -y fonts-dejavu-core && rm -rf /var/lib/apt/lists/*
```

For Alpine-based images:

```bash
apk add --no-cache fontconfig ttf-dejavu
```

After installing a font manually (for example by copying a `.ttf` file into `/usr/local/share/fonts`), rebuild the cache:

```bash
fc-cache -fv
```

## 4. Point FFmpeg at the Font File

When `drawtext` still cannot resolve the family name, switch to the explicit `fontfile` parameter:

```bash
...drawtext=fontfile='/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf':text='Hello World'...
```

Using the absolute file path bypasses the fontconfig lookup, which is especially useful inside slim containers.

## 5. Keep Fonts with Your Image

To ensure reproducible builds:

1. Install the fonts in your `Dockerfile`.
2. Run `fc-cache -fv` as part of the build stage.
3. Document the font paths you rely on so future updates can validate them quickly.

Following this workflow makes it straightforward to debug FFmpeg `drawtext` errors and guarantees that automated builds render text overlays consistently.
