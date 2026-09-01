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
php artisan package:installrequire hkyss/evocms-extras "^1.3"
php artisan migrate
php artisan extra:doctor
php artisan extra:takeover --dry-run
```

Run `migrate`. Legacy extras refuse to install without the record table, since there would be no
way to remove them afterwards.

`extra:doctor` checks the things Composer cannot: PHP version, `ext-zip`, a writable
`custom/composer.json`, whether `composer-merge-plugin` is enabled, whether `GITHUB_PAT` is set.
Start there when something misbehaves.

`extra:takeover` says what the site is running that this package does not manage, and takes it
over when you drop the `--dry-run`; see [Taking the legacy manager over](#taking-the-legacy-manager-over).

Config is optional:

```bash
php artisan vendor:publish --tag=extras-config
```

## In the manager

The package registers a module of its own and puts it in the manager header, beside Modules
rather than inside it. Its page carries its own stylesheet and script, so nothing of this
package is published into the doc root. It opens on two tabs.

**Installed** is what this tool manages — the requirements in `core/custom/composer.json` and the
legacy install records — with the version, the compatibility status and, for a legacy extra, how
many files and elements it laid down and when. Nothing here waits on the network.

**Catalog** is everything the configured sources know about, plus anything installed here that
they do not list. Installed rows are marked and carry their version; search and filters over
format and installed state narrow the list.

Rows on both tabs link out. The mark beside the name opens the repository the extra ships from —
the url Composer clones for a package, the repository itself for a legacy extra — and the name
opens the site the package declares, where it declares one.

Install and removal both show the plan before anything is written — the same plan `--dry-run`
prints, down to the files a removal will leave alone because you edited them. Only then is there a
button. An extra with more than one published version gets a picker, and one that has never been
verified against Evo 3 needs a tick to go ahead, the way `--force` works on the console. A
platform requirement this installation does not meet has no tick: Composer would refuse for the
same reason.

The package never offers to install or remove itself. A module cannot rebuild the code it is
running from.

One write at a time: an install or a removal takes a lock, and a second request while one is
running is answered `409` rather than left to run two Composer resolutions over one `core/vendor`.

The endpoints live under `/api/v1/extras/admin` and answer to a signed-in manager and nobody else.

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

## Interactive mode

Run `extra:install`, `extra:update` or `extra:remove` without a coordinate in a terminal and the
command asks instead of failing:

```
Install which extra?
  → swa  (1/160)
 ❯ ◉ vvvladv/evo-swagger  composer  OpenAPI / Swagger UI manager module and apidocs…

  ↑↓ move  ·  space select  ·  enter confirm  ·  esc clear filter
```

Arrow keys move, typing narrows the list, space ticks several, enter confirms, escape clears the
filter and then backs out. Picking one extra also offers its published versions, and the plan is
always confirmed before anything is written.

The read-only commands use the same list where it saves you a lookup:

```bash
php artisan extra:list                 # browse; enter opens a card, esc leaves
php artisan extra:list --format=table  # the plain table, whatever the terminal
php artisan extra:info                 # no coordinate: pick one from the catalog
```

Browsing is not read-only: the card is followed by what can be done with that extra — `install`
for something absent, `update` and `remove` for something present, or back to the list. The plan
is printed and confirmed before anything is written, exactly as it is for `extra:install`.

`extra:cache --clear` and `extra:cache --rebuild-snapshot` ask first — the cache costs minutes to
rebuild, and the snapshot is a file under version control.

This is additive. Passing coordinates, `--no-interaction`, piping the output, or running where
`stty` is unavailable takes exactly the same path as before — `extra:update` with no argument
still means "everything installed" when unattended.

Setting `CI` or `CONTINUOUS_INTEGRATION` also turns every prompt off. A runner allocates a
terminal to get coloured logs, and so does `docker exec -t` in a Makefile; without this a prompt
there would wait for a keypress that never comes and the job would hang rather than fail.

Set `EXTRAS_ASCII=1` to replace the box drawing and glyphs with plain ASCII; this happens
automatically on Windows outside Windows Terminal.

## Commands

Start with `php artisan extra:help` — one screen covering every command, the flags worth
knowing and the interactive keys. `php artisan extra:<command> --help` has the full option list.

### `extra:list`

```bash
php artisan extra:list
php artisan extra:list --search=commerce
php artisan extra:list --installed
php artisan extra:list --legacy --verified
php artisan extra:list --format=table
php artisan extra:list --format=json
```

`--composer` and `--legacy` filter by format, `--verified` keeps only what has been checked on
Evo 3.

`--installed` is answered from `custom/composer.json` and the install records rather than from
the catalog, so it stays complete when the catalog is stale, rate-limited or unreachable. An
extra installed here that the catalog does not list is shown as `installed; not listed in the
catalog`.

`--format` decides how the result is shown:

| | |
|---|---|
| `auto` (default) | the browsable list in a terminal, the table everywhere else |
| `list` | the browsable list; degrades to the table when there is no terminal |
| `table` | the table, always |
| `json` | machine-readable, never interactive |

Anything that is not a terminal — a pipe, a redirect, `--no-ansi`, `--no-interaction`, CI — gets
the table under `auto`, so scripts do not need to pass a flag to stay scriptable.

### `extra:install`

```bash
php artisan extra:install evolution-cms-extras/tinymce5
php artisan extra:install evolution-cms-extras/tinymce5 --use-version=5.7.1
php artisan extra:install a/one b/two c/three
php artisan extra:install --file=extras.txt --continue-on-error
php artisan extra:install extras-evolution/Ditto --dry-run
```

Called with no coordinate in a terminal it opens the catalog as a filterable list; see
[Interactive mode](#interactive-mode).

Pass as many coordinates as you like; there is no separate batch command. `--file` reads one per
line and treats `#` as a comment.

`--force` proceeds past an objection such as an extra that has never been verified on Evo 3. It
does not cover a platform requirement — a `php` constraint the installation does not meet, or a
missing extension. Those are checked before the manifest is touched, and Composer would refuse
for the same reason anyway. `--ignore-platform-reqs` is the way past those, for when the catalog
is wrong about what a package needs.

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

With no arguments it offers the installed extras as a list to tick, and updates everything
installed when run unattended.

### `extra:remove`

```bash
php artisan extra:remove evolution-cms-extras/tinymce5
php artisan extra:remove a/one b/two --continue-on-error
```

### `extra:takeover`

```bash
php artisan extra:takeover --dry-run
php artisan extra:takeover
php artisan extra:takeover --restore
```

### `extra:info`, `extra:cache`, `extra:doctor`, `extra:help`

```bash
php artisan extra:help

php artisan extra:info
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

## Taking the legacy manager over

Evolution ships an extras manager of its own — the module running out of `assets/modules/store` —
and what a site installed through it is a set of rows and files nothing can take back: the legacy
format has no uninstall, and this package removes only what it has a record of.

`extra:takeover` reads the site against the catalog and does three things:

- **retire** the legacy manager's own module. This package is what replaces it, so nothing is
  installed in its place. A row the catalog answers for with a package that is *already* installed
  is retired too, for the same reason.
- **adopt** a legacy extra the site is already running: the rows it has are set aside — renamed
  with the same `.old` suffix a backed-up file gets, and switched off — and the extra is installed
  again through here beside them, so it gains an install record and can be removed afterwards. Set
  aside rather than written over: an installer of the legacy format finds its elements by name, so
  a row left under its own would be overwritten and there would be nothing left to switch back on.
  The version is the one the site is running, where the catalog publishes that one — taking a site
  off the version it chose is an update, not a takeover.
- **replace** a row the catalog answers for with a Composer package: the row goes dark and the
  package is installed in its place. `CodeMirror` for `evolution-cms/ecodemirror` is the one
  mapping shipped; the rest of the map is yours, under `takeover.replacements`.

Everything else is left exactly as it is — **nothing the catalog cannot name is ever switched
off**, the legacy manager's own module aside, which is what the command exists for — and the plan
says why row by row: an element whose description carries no version was typed into the manager
rather than written by a package; one the catalog cannot name has nothing to install in its place;
and a replacement whose `php` or `ext-*` requirement this installation does not meet is left out,
because Composer would refuse over the same line and the row it would have replaced is better off
running. Plugins, modules and snippets are in scope; templates, chunks and TVs are content, and a
template switched off is a page that stops working.

An extra whose source turns out to hold no package at all — which only the download can say — is
left as it was too, and the run says so and carries on.

`takeover.ignore` names rows to leave alone whatever the catalog answers with. `Updater` ships in
it: `extras-evolution/Updater` publishes no tag, so a takeover would install its default branch
over the release Evolution ships, and the element that arrives carries no version in its
description — which is where Evolution's own `OutdatedExtrasCheck` reads one, so the manager warns
on every page about a plugin this tool had just installed. Nothing was attempted for it, so it is a
row fewer in the takeover rather than a takeover that failed.

A stock Evolution CE 3.1 install, on PHP 8.2:

```
$ php artisan extra:takeover --dry-run

extra:takeover
what the legacy manager left, under this one
────────────────────────────────────────────

retire
  switched off and not replaced: this package is what replaces it
  └─ ● switch off module Extras — this package is the manager now

adopt
  set aside, and the same extra installed again through this tool
  ├─ ● set plugin AboutEvoWidget aside and install evocms-community/AboutEvoWidget
  └─ ● set plugin Updater aside and install extras-evolution/Updater

skip
  left as it is
  ├─ · leave plugin CodeMirror alone — evolution-cms/ecodemirror needs PHP ^8.3; this installation runs 8.2.29
  └─ · leave plugin TransAlias alone — nothing in the catalog answers to the name

--dry-run: nothing was changed
```

On PHP 8.3 the `CodeMirror` row moves out of `skip` and into `replace`: the plugin goes dark and
`evolution-cms/ecodemirror` is installed in its place.

The manager shows both copies afterwards. `Updater.old` is switched off and exactly as the site
had it, down to the version in its description; `Updater` beside it is the one this tool installed
and the one the site runs. `--restore` deletes the second and gives the first its name back.

One install that fails takes the whole takeover with it: what the run had already switched off
comes back on, and the site is left as it was found.

`extra:takeover --restore` is the other direction — what the takeover installed is removed through
the installer that put it there, and every row it switched off comes on again. **Run it before you
remove this package**, while `extras_takeover_records` is still there: that table is the only thing
that knows what a takeover did, and it leaves with the migrations.

```bash
php artisan extra:takeover --restore
cd core && composer remove hkyss/evocms-extras
```

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
- Illuminate `http`, `routing` and `view`, which the manager module needs and Evolution ships

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
