# Contributing

## Setup

```bash
git clone https://github.com/hkyss/evocms-extras.git
cd evocms-extras
composer install
composer check
```

`composer check` runs php-cs-fixer, phpstan and phpunit; `composer cs:fix` applies the style fixes.

Parsing and planning code needs no Evolution install. Anything inside `apply()` does.

`composer.json` pins `config.platform.php` to 8.2.0 so dependencies resolve for the lowest
supported runtime rather than whatever PHP you happen to have installed.

## Pull requests

- `composer check` passes.
- New behaviour has a test. If it's pure, it goes in `tests/Unit`.
- No inline comments. A one-sentence annotation above a method is fine where the code can't say it
  itself.
- English throughout, including commit messages.

## Naming

The codebase is strict about a few words:

- `Extra` is one extra, never `Package`. The core already uses "package" for anything deployed
  into `core/custom/packages/`.
- `Coordinate` is `vendor/package` or `org/repo`, whatever the user typed.
- `CatalogSource` is a source of listings, never `Repository`. That word belongs to GitHub
  repositories.
- `Installer` is an adapter for one format, never `Manager`.
- `Store` is the legacy manager module in the core, not this.

## Testing

Unit tests cover descriptor parsing, SQL splitting, property merging, asset comparison and catalog
assembly.

Fixtures in `tests/Fixtures/legacy/` are unmodified files from PageBuilder, Shopkeeper and
ManagerManager. The MODX Evolution Package format has no complete written specification, so real
files are the reference. Keep them verbatim, BOM included.

Plan execution needs a CE checkout with a database and isn't part of `composer test`.

## Catalog snapshot

`resources/catalog.json` is generated, but the compatibility statuses in it are set by hand and
survive regeneration:

```bash
GITHUB_PAT=… php artisan extra:cache --rebuild-snapshot=resources/catalog.json
```

Marking a legacy extra `verified` means you installed it on Evolution CMS 3 and it worked. Say
which version and what you checked in the pull request.

## Releases

Tags are `vX.Y.Z`, [CHANGELOG.md](CHANGELOG.md) follows Keep a Changelog. On `0.x` a minor version
may break compatibility; the changelog will say so.
