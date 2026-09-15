# Admidio Plugin Repository API for DokuWiki

Prototype DokuWiki action plugin that exposes plugin metadata stored in the
Struct schemas `admidio_plugin` and `admidio_plugin_release` as a
compatibility-aware JSON repository.

## Requirements

- DokuWiki
- Struct plugin enabled
- Struct page schema `admidio_plugin`
- Struct serial schema `admidio_plugin_release`

The plugin deliberately uses Struct's `ConfigParser` + `SearchConfig` API and
does not read `struct.sqlite3` directly.

## Installation

Copy this directory to:

    lib/plugins/admidio_repository/

The directory name must be exactly `admidio_repository`.

Then make sure the plugin is enabled in DokuWiki's Extension Manager.

## Endpoint

Example:

    /doku.php?do=admidio_repository&admidio=5.1.5&php=8.2.12&channel=stable

Required parameters:

- `admidio`: installed Admidio version
- `php`: installed PHP version

Optional parameters:

- `channel=stable|beta|development` (default: `stable`)
- `plugin=<plugin_id>` to request one plugin only

Aliases:

- `channel=final` -> `stable`
- `channel=dev` -> `development`

## Release selection

For each plugin, the endpoint:

1. excludes archived/unmaintained plugins;
2. checks release channel;
3. checks inclusive `admidio_min` / `admidio_max`;
4. checks inclusive `php_min` / `php_max`;
5. chooses the highest compatible `version` using PHP `version_compare()`.

`featured` is intentionally ignored. It can still be used by the human-facing
DokuWiki aggregation page.

### Channel mapping

- `stable`: stable only
- `beta`: stable, rc, beta
- `development`: stable, rc, beta, alpha, development/dev

## Expected Struct fields

### admidio_plugin

- plugin_id
- name
- description
- author
- homepage
- repository
- license
- category
- plugin_status
- tags

### admidio_plugin_release

- version
- release_date
- admidio_min
- admidio_max
- php_min
- php_max (optional; query automatically retries without it)
- download
- sha256
- release_status
- notes

The obsolete `plugin` column in the release schema is ignored.

## Version range semantics

Minimum and maximum versions are inclusive. Empty means unbounded.

For example:

    admidio_min = 5.1
    admidio_max =

means all Admidio versions `>= 5.1`.

Do not use `admidio_max = 5.1` to mean "all 5.1.x versions". It means exactly
an upper bound of version 5.1, so 5.1.5 would be greater than it. In practice,
leave the maximum empty unless a real incompatibility is known.

## Example response

```json
{
  "repository_version": 1,
  "generated_at": "2026-09-15T11:00:00+00:00",
  "environment": {
    "admidio": "5.1.5",
    "php": "8.2.12",
    "channel": "stable"
  },
  "plugins": [
    {
      "id": "impersonate",
      "name": "Impersonate",
      "release": {
        "version": "1.0.0",
        "requires": {
          "admidio": {"min": "5.1", "max": null},
          "php": {"min": "8.2", "max": null}
        }
      }
    }
  ]
}
```

## Tests to perform manually

With sample releases such as:

- 1.0.0 stable, Admidio >= 5.1, PHP >= 8.2
- 1.1.0-beta1 beta, Admidio >= 5.1, PHP >= 8.2
- 1.1.0 stable, Admidio >= 5.2, PHP >= 8.2

check:

    ?do=admidio_repository&admidio=5.1.5&php=8.2&channel=stable

selects 1.0.0,

    ?do=admidio_repository&admidio=5.1.5&php=8.2&channel=beta

selects 1.1.0-beta1, and

    ?do=admidio_repository&admidio=5.2&php=8.2&channel=stable

selects 1.1.0.
