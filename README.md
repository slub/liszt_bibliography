The Bibliography Module of the Liszt Portal
===========================================

[![TYPO3 12](https://img.shields.io/badge/TYPO3-12-orange.svg)](https://get.typo3.org/version/12)
[![License](https://img.shields.io/github/license/slub/liszt_bibliography)](https://github.com/slub/liszt_bibliography/blob/main/LICENSE)

## What this does

TYPO3 extension (`liszt_bibliography`) that fetches bibliographical entries from the
Zotero API and indexes them into Elasticsearch. A frontend plugin for browsing the
resulting bibliography is scaffolded (`Configuration/TCA/Overrides/tt_content.php`,
`ext_localconf.php`) but its registration is currently commented out, and no
Controller class exists yet — it does not render in the frontend as shipped.

## Tech stack

- PHP 8.2, TYPO3 12 (CMS core, fluid-styled-content, scheduler)
- Elasticsearch 8 (via `elasticsearch/elasticsearch`)
- Zotero API client: `dikastes/zotero-api`
- `illuminate/collections`, `slub/liszt-common`
- Tests: PHPUnit 9 (`typo3/testing-framework`), PHPStan
- CI: GitHub Actions (`.github/workflows/ci.yml`)

## Install

```
composer require slub/liszt-bibliography
```

This pulls in two non-Packagist repositories declared in `composer.json`
(`dikastes/zotero-api`, `slub/liszt-common`) — no extra setup needed, Composer
resolves them from the `repositories` block.

For local development, clone and install dependencies directly:

```
git clone https://github.com/slub/liszt_bibliography.git
cd liszt_bibliography
composer install
```

## Configuration

Set these in the TYPO3 extension configuration (`ext_conf_template.txt`):

| Setting | Purpose |
|---|---|
| `elasticIndexName`, `elasticLocaleIndexName`, `elasticBulkSize` | Elasticsearch index names and indexing batch size |
| `zoteroApiKey`, `zoteroGroupId`, `zoteroUserId` | Zotero API credentials |
| `zoteroBulkSize` | Batch size for fetching entries from Zotero |
| `zoteroLocale`, `zoteroStyle`, `zoteroLinkwrap` | Citation locale/style rendering options |
| `zoteroCollectionId` | Zotero collection IDs to index |
| `collectionToItemTypeMap` | JSON map overriding item type per collection ID, e.g. `{"URC5G9EI":"printedMusic"}` |
| `logLevel` | Log level for the index command (`INFO`/`WARNING`/`ERROR`) |

## Usage

Run the indexer via the TYPO3 console command:

```
typo3 liszt-bibliography:index
```

The frontend plugin is not yet registered (see note above) — there is currently
no way to display the bibliography listing on a page.

## Build / CI

CI (`.github/workflows/ci.yml`) runs three jobs via Composer scripts. Test
execution (`ci:tests:unit`, `ci:tests:functional`) runs through
`Build/Scripts/runTests.sh` in a Docker test container; the `functional-tests`
job's dependency-install step diverges and runs plain `composer update
--no-progress` instead of `composer ci:install`:

```
composer ci:install       # install dependencies (Docker)
composer ci:php:stan      # PHPStan static analysis
composer ci:tests:unit    # PHPUnit unit tests
composer ci:tests:functional  # PHPUnit functional tests
```

Run everything at once:

```
composer ci
```

## Test-run instructions

Unit and functional tests run against a Docker/Podman container via
`Build/Scripts/runTests.sh` (see `-h` for all options, e.g. PHP version,
database backend):

```
Build/Scripts/runTests.sh -s unit -b docker
Build/Scripts/runTests.sh -s functional -b docker
```

Functional tests default to SQLite but can target MariaDB or Postgres; see the
`functional_mariadb10` / `functional_postgres10` / `functional_sqlite` services
in `Build/testing-docker/docker-compose.yml`.

## Logging

The index command logs successful runs at info level and errors at error level
to `var/log/liszt_bibliography_commands.log`. Keep logs at those levels (see
[TYPO3 logging configuration](https://docs.typo3.org/m/typo3/reference-coreapi/main/en-us/ApiOverview/Logging/Configuration/Index.html))
and check them regularly.

## License

GPLv3 (see `LICENSE`). Note: `composer.json` declares `GPL-2.0-or-later`, which
does not match the `LICENSE` file — worth reconciling separately.

## Maintainers

- [Matthias Richter](https://github.com/dikastes)
