[![Latest version](https://img.shields.io/github/v/release/magicsunday/photo-memories?sort=semver)](https://github.com/magicsunday/photo-memories/releases/latest)
[![License](https://img.shields.io/github/license/magicsunday/photo-memories)](https://github.com/magicsunday/photo-memories/blob/main/LICENSE)
[![CI](https://github.com/magicsunday/photo-memories/actions/workflows/ci.yml/badge.svg)](https://github.com/magicsunday/photo-memories/actions/workflows/ci.yml)

# Photo Memories

## Localised Points of Interest

Photo Memories enriches locations with nearby points of interest fetched from the OpenStreetMap Overpass API. These POIs now
capture all available `name:*` variants plus optional `alt_name` entries. The application stores them in a dedicated `names`
structure alongside the legacy `name` field so consumers can choose the most appropriate label for their locale.

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
