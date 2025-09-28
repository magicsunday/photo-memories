[![Latest version](https://img.shields.io/github/v/release/magicsunday/photo-memories?sort=semver)](https://github.com/magicsunday/photo-memories/releases/latest)
[![License](https://img.shields.io/github/license/magicsunday/photo-memories)](https://github.com/magicsunday/photo-memories/blob/main/LICENSE)
[![CI](https://github.com/magicsunday/photo-memories/actions/workflows/ci.yml/badge.svg)](https://github.com/magicsunday/photo-memories/actions/workflows/ci.yml)

# Photo Memories

## Configuration

### Preferred POI language

Point of interest names returned by the Overpass API can now include
localised variants. Set the `memories.locale.preferred` container parameter
or the `MEMORIES_LOCALE_PREFERRED` environment variable (default `de`) to the
desired locale (for example `de` or `en_GB`) to prefer the matching
`name:<locale>` tag when rendering POI labels. You can override the default
by adjusting `config/parameters.yaml` or supplying an environment-specific
configuration.
