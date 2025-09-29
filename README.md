[![Latest version](https://img.shields.io/github/v/release/magicsunday/photo-memories?sort=semver)](https://github.com/magicsunday/photo-memories/releases/latest)
[![License](https://img.shields.io/github/license/magicsunday/photo-memories)](https://github.com/magicsunday/photo-memories/blob/main/LICENSE)
[![CI](https://github.com/magicsunday/photo-memories/actions/workflows/ci.yml/badge.svg)](https://github.com/magicsunday/photo-memories/actions/workflows/ci.yml)

# Photo Memories

## Localised Points of Interest

Photo Memories enriches locations with nearby points of interest fetched from the OpenStreetMap Overpass API. These POIs now
capture all available `name:*` variants plus optional `alt_name` entries. The application stores them in a dedicated `names`
structure alongside the legacy `name` field so consumers can choose the most appropriate label for their locale.

By default the Overpass enrichment focuses on sightseeing-related categories to reduce noise. The whitelist currently includes:

| Tag key   | Allowed values                      |
|-----------|-------------------------------------|
| `tourism` | `attraction`, `viewpoint`, `museum`, `gallery` |
| `historic`| `monument`, `castle`, `memorial`     |
| `man_made`| `tower`, `lighthouse`               |
| `leisure` | `park`, `garden`                    |
| `natural` | `peak`, `cliff`                     |

You can extend this list without touching the code by overriding the Symfony parameter
`memories.geocoding.overpass.allowed_pois` (e.g. in `config/parameters.local.yaml` or environment specific configuration).
The entries are merged with the defaults so new keys or values become part of the Overpass query and validation pipeline.

To control which language is preferred when rendering titles or cluster labels, configure the new
`MEMORIES_PREFERRED_LOCALE` environment variable (or its matching Symfony container parameter
`memories.localization.preferred_locale`). When set, `LocationHelper::displayLabel()` and related helpers first attempt to use
the matching `name:<locale>` value before falling back to the default name, other available translations, or alternative
labels.

Example `.env.local` snippet:

```dotenv
MEMORIES_PREFERRED_LOCALE=de
```

Leave the variable unset to retain the previous behaviour of using the generic `name` tag provided by Overpass.
