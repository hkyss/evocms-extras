# Extras Manager

[![Tests](https://github.com/hkyss/evocms-extras/actions/workflows/tests.yml/badge.svg)](https://github.com/hkyss/evocms-extras/actions/workflows/tests.yml)
[![Latest version](https://img.shields.io/packagist/v/hkyss/evocms-extras.svg)](https://packagist.org/packages/hkyss/evocms-extras)
[![PHP](https://img.shields.io/packagist/dependency-v/hkyss/evocms-extras/php.svg)](composer.json)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

Console extras manager for Evolution CMS CE 3 ([evocms-community/evolution](https://github.com/evocms-community/evolution)).
Lists, installs, updates and removes extras in both formats the ecosystem uses.

```bash
php artisan extra:list --search=commerce
php artisan extra:install evolution-cms-extras/tinymce5
php artisan extra:remove evolution-cms-extras/tinymce5 --dry-run
```

CE 3.1 ships `php artisan extras`, an interactive prompt tied to one GitHub organisation. It has
no listing, no search, no JSON output and no dry run, and there is no removal command anywhere in
the core. `package:installrequire` also drops Composer's exit code, so a failed install reports
success and leaves the requirement in the manifest.

## Install

```bash
cd core
php artisan package:installrequire hkyss/evocms-extras "^0.1"
php artisan migrate
php artisan extra:doctor
```

Run `migrate`. Legacy extras refuse to install without the record table, since there would be no
way to remove them afterwards.

`extra:doctor` checks the things Composer cannot: PHP version, `ext-zip`, a writable
`custom/composer.json`, whether `composer-merge-plugin` is enabled, whether `GITHUB_PAT` is set.
Start there when something misbehaves.

Config is optional:

```bash
php artisan vendor:publish --tag=extras-config
```

## Formats

**Composer extras** are packages of type `evolutioncms-*` on Packagist, about two dozen of them.
They go into `core/custom/composer.json`, and the core's `package:discover` picks up their
providers and assets.

**Legacy extras** use the MODX Evolution Package format: an `assets/` tree copied into the site,
plus an `install/` directory holding element descriptors and an optional `setup.data.sql`. Most of
the ecosystem is here — [extras-evolution](https://github.com/extras-evolution) has around 130
repositories and [evocms-community](https://github.com/evocms-community) another 30, none with a
`composer.json`. Commerce and its payment plugins, PageBuilder, ClientSettings, Ditto, eForm,
ajaxSearch, Shopkeeper and ManagerManager are all legacy.

The legacy format has no uninstall of its own, which is why this package keeps its own
[install record](#removal).

## Commands

### `extra:list`

```bash
php artisan extra:list
php artisan extra:list --search=commerce
php artisan extra:list --installed
php artisan extra:list --legacy --verified
php artisan extra:list --format=json
```

`--composer` and `--legacy` filter by format, `--verified` keeps only what has been checked on
Evo 3.

### `extra:install`

```bash
php artisan extra:install evolution-cms-extras/tinymce5
php artisan extra:install evolution-cms-extras/tinymce5 --use-version=5.7.1
php artisan extra:install a/one b/two c/three
php artisan extra:install --file=extras.txt --continue-on-error
php artisan extra:install extras-evolution/Ditto --dry-run
```

Pass as many coordinates as you like; there is no separate batch command. `--file` reads one per
line and treats `#` as a comment.

`--use-version` works on a single extra only. Applying one version string to several packages
installs the wrong thing more often than not. The flag is not called `--version` because Symfony
defines that one on the console application itself, and a command that shadows it cannot run at
all.

### `extra:update`

```bash
php artisan extra:update
php artisan extra:update evocms-community/commerce
php artisan extra:update evocms-community/commerce --use-version=1.4.0
php artisan extra:update --dry-run
```

With no arguments it updates everything installed.

### `extra:remove`

```bash
php artisan extra:remove evolution-cms-extras/tinymce5
php artisan extra:remove a/one b/two --continue-on-error
```

### `extra:info`, `extra:cache`, `extra:doctor`

```bash
php artisan extra:info evocms-community/pagebuilder
php artisan extra:info evocms-community/pagebuilder --format=json

php artisan extra:cache
php artisan extra:cache --clear

php artisan extra:doctor
```

## Dry runs

Every operation is assembled as a full plan first. `--dry-run` prints it and changes nothing:

```
$ php artisan extra:install evocms-community/pagebuilder --dry-run

install evocms-community/pagebuilder  [legacy]
  version: — → master

  file
    · create assets/plugins/pagebuilder/pagebuilder.php
    · back up assets/plugins/pagebuilder/lang/en.php → .old
    · overwrite assets/plugins/pagebuilder/lang/en.php
  element
    · create plugin PageBuilder
    · create snippet PageBuilder
  sql
    · sql: CREATE TABLE IF NOT EXISTS `evo_pagebuilder` (…)
    · sql: ALTER TABLE evo_pagebuilder ADD COLUMN container varchar(255)…

  compatibility of 'evocms-community/pagebuilder' with Evolution CMS 3 is unknown
```

Evolution CE keeps `core/vendor/` under version control — 5344 files, 51 MB — so any Composer
install turns into a diff of thousands of files in your working tree.

## Compatibility

Most legacy extras were written for MODX Evolution 1.x. Ditto was last touched in 2021,
ManagerManager in 2019. The format has no field for declaring compatibility with Evo 3, so every
entry carries a status set by hand:

- `verified` — installed and checked on Evolution CMS 3
- `unknown` — never checked, which is the default for legacy extras
- `incompatible` — known to break

`extra:list` shows all of them. `extra:install` refuses `unknown` and `incompatible` unless you
pass `--force`.

## Removal

Removal takes back what the extra brought and leaves anything you changed:

- A file is deleted only if it still matches the installed copy byte for byte. Anything you edited
  stays, and the output tells you what was left.
- Files overwritten during install are restored from their `.old` backups.
- Elements created by the install are deleted. Elements that existed beforehand are reverted to
  their previous code.
- Elements marked `@overwrite false` in their docblock are never touched. Authors use that tag for
  config chunks people are expected to edit.
- Schema is not rolled back. Tables and columns an extra created stay, because the format has no
  down migrations. The plan says so before you confirm.

## Catalog

Sources are configured in order, and the first one holding a coordinate wins:

```php
'sources' => [
    ['driver' => 'snapshot',   'name' => 'Bundled snapshot'],
    ['driver' => 'packagist',  'name' => 'Packagist', 'types' => ['evolutioncms-plugin', /* … */]],
    ['driver' => 'github-org', 'name' => 'Extras-Evolution', 'organization' => 'extras-evolution'],
    ['driver' => 'github-org', 'name' => 'EvoCMS Community', 'organization' => 'evocms-community'],
],
```

The snapshot ships with the package and comes first. Walking the legacy organisations costs
hundreds of GitHub requests against an anonymous limit of 60 per hour, and compatibility statuses
have nowhere else to live.

Rebuilding it needs a token and is done separately, then committed:

```bash
GITHUB_PAT=ghp_… php artisan extra:cache --rebuild-snapshot=vendor/hkyss/evocms-extras/resources/catalog.json
```

Statuses set by hand survive a rebuild. `GITHUB_PAT` is the variable the core already reads, so an
existing token works as is.

## How it plugs into Evolution

The core does the wiring; this package writes the manifest and runs Composer.

```
composer update in core/
   └─ post-autoload-dump → php artisan package:discover
        ├─ core/custom/composer.json
        ├─ extra.laravel.providers → core/custom/config/app/providers/<Class>.php
        ├─ extra.laravel.files     → assets copied into the site tree
        └─ provider manifest reset
   └─ ProviderRepository loads the providers
```

Requirements go into `core/custom/composer.json`, which `wikimedia/composer-merge-plugin` merges
into the core manifest. The core's own `composer.json` is never modified.

Composer runs in-process, with its exit code checked and the manifest restored if it fails. A
subprocess would need a `composer` binary in `PATH`, which shared hosting usually lacks — the core
bundles `composer/composer` for the same reason.

## Requirements

- PHP `^8.2`
- Evolution CMS CE 3.1.x, tested on 3.1.30
- `ext-json`, `ext-zip`
- `composer/composer`, already a core dependency

The CMS cannot be declared as a Composer dependency: `evocms/evolution` is published as
`type: project` and `evocms/core` is not published at all. `extra:doctor` checks the platform at
runtime instead.

## Development

```bash
composer install
composer check
```

`composer check` is php-cs-fixer, phpstan level 6 and phpunit. Unit tests cover the planning side — descriptor parsing, SQL splitting, property merging, tree
comparison, catalog assembly — and need no network, database or Evolution install. Fixtures are
unmodified files from real extras. Plan execution is covered separately against a real CE checkout.

See [CONTRIBUTING.md](CONTRIBUTING.md).

## License

MIT, see [LICENSE](LICENSE). Changes are listed in [CHANGELOG.md](CHANGELOG.md); what installing an
extra actually does to your server is described in [SECURITY.md](SECURITY.md).
