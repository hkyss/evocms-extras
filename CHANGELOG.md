# Changelog

Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), versioning follows
[SemVer](https://semver.org/spec/v2.0.0.html). From 1.0.0 the public surface — the commands and
their options, the `Installer` interface, the catalog snapshot schema — is stable: a breaking
change needs a major version.

## [Unreleased]

### Fixed

- A legacy plan nobody applied left its unpacked archive behind. Planning downloads and unpacks the
  extra to see what it would write, and only `apply` cleaned up afterwards, so a `--dry-run`, a plan
  with nothing to do, a blocked plan and one that failed halfway each left a temp directory. The
  console now releases it in a `finally`, through the new `HoldsArchives`.

## [1.0.1] - 2026-08-28

Repository housekeeping; the package itself is unchanged from 1.0.0.

### Fixed

- The test workflow ran on pushes to `main`, a branch this repository does not have, so between
  pull requests nothing was checked at all. It follows `dev` now, like the rest of the family.
- The issue template pointed at `SECURITY.md` on `main` and answered 404.

### Changed

- The Packagist keyword is `evolution-cms`, the spelling everything else is published under; this
  package alone said `evolutioncms` and fell out of the neighbouring search results.

## [1.0.0] - 2026-08-18

First stable release. Everything below is relative to 0.2.0.

### Breaking

- `extra:list` run in a terminal shows the browsable list rather than the table; the previous
  default was the table. `--format` takes `auto` (the default), `list`, `table` or `json`, so
  `--format=table` asks for the old output explicitly.

  Nothing unattended changed. A pipe, a redirect, `--no-ansi` and `--no-interaction` all still
  get the table, and so does anything running with `CI` or `CONTINUOUS_INTEGRATION` set — a
  runner allocates a pty to get coloured logs, and a prompt there would wait for a keypress that
  never comes. The same guard covers every prompt, not just this one.

### Added

- An interactive mode. `extra:install`, `extra:update` and `extra:remove` called without a
  coordinate now open a filterable list — arrow keys to move, type to narrow, space to tick
  several, enter to confirm — instead of failing with "nothing to install". Picking an extra
  from the list also offers its published versions, and every plan is confirmed before it is
  written. Long steps (walking the catalog, running Composer) show a spinner.
- `extra:info` no longer requires a coordinate: without one it offers the catalog as a list.
  It used to answer `Not enough arguments (missing: "coordinate")`, which is Symfony's error,
  not an answer.
- `extra:list` browses: pick a row, read its card, then install, update or remove it right
  there — the plan is printed and confirmed as usual — or go back to the list; escape leaves.
- `extra:cache --clear` and `--rebuild-snapshot` ask before they destroy something. Clearing 39
  cached responses is minutes of re-walking the sources, and without `GITHUB_PAT` it runs into
  the anonymous rate limit; the snapshot rebuild overwrites a file that is checked in.
- `extra:help`: what the package is, the seven commands with the flags worth knowing, and the
  interactive keys — one screen, for someone meeting it for the first time. It reads the command
  names off the console application, so a command added later cannot go missing from it.
- A platform pre-flight check. An extra whose `require.php` the running PHP does not satisfy, or
  which needs an extension this PHP does not load, is now blocked while the plan is built —
  before the manifest is touched and before Composer resolves anything. Failing took a full
  Composer run and a manifest rollback; it now takes 0.2s and writes nothing. Plans separate the
  two kinds of objection, `blockers()` (overridable with `--force`) from `forbidden()` (not).

  `--ignore-platform-reqs`, named after Composer's own flag, is the way past it. The constraint
  comes from the catalog, which can be stale or wrong, so a check nothing could override would
  make a bad entry unfixable from here. No invocation that used to succeed fails now — Composer
  refused these already, just slower and after touching the manifest.
- The bundled snapshot's `require` was refreshed from Packagist: 20 of its 24 composer entries
  now carry a `php` constraint, where none did before. Nine of them publish only dev branches
  and had to come from Packagist's `~dev` endpoint — the same fallback `PackagistSource` uses.
- `Console\Ui`, one rendering layer for all seven commands: box-drawn tables, grouped status
  lines with `✔ / ▲ / ✖` marks, borderless key/value blocks, plan steps as a tree, and counts in
  a footer. `EXTRAS_ASCII=1` — and Windows outside Windows Terminal — falls back to ASCII.

### Changed

- `Console\ExtraPresenter` holds how one extra is drawn — table row, selector option, full card —
  so `extra:info` and `extra:list --browse` render the same card from one place.
- `extra:doctor` groups its checks (platform, composer, records, catalog) instead of printing one
  flat table, and wraps long details under a hanging indent.

### Fixed

- `extra:list --installed` listed what is installed by walking the catalog and keeping the
  entries marked installed, so its answer depended on Packagist and GitHub answering. A rate
  limit, an offline run, or a package pulled from Packagist silently shortened the one list that
  must always be complete. It is now driven by the manifest and the install records, with the
  catalog used only to describe what it finds; anything installed that the catalog does not list
  still appears, marked as such. The enumeration is a separate `EnumeratesInstalled` interface,
  so `Installer` itself is unchanged from 0.2.0 and an installer written against it still
  works — it simply contributes nothing to the installed list.
- The catalog dropped `php` and `ext-*` from an extra's requirements, keeping only the packages
  it depends on. Those are the two constraints that decide whether an extra can be installed at
  all, so a rebuilt snapshot carried everything except the part worth knowing in advance —
  `evolution-cms/etinymce` looked installable on PHP 8.2 right up to the Composer resolve.
  `extra:cache --rebuild-snapshot` now records them, and `extra:info` shows them.
- A failed Composer resolve printed only Composer's own wall of text, which opens with
  "Your requirements could not be resolved" and buries the cause in an arrow expression three
  lines down. Platform causes — a PHP constraint the installation does not meet, a missing
  extension, a package Packagist does not serve — are now stated in one sentence above the raw
  output. A genuine dependency conflict is left to Composer, which says it better than a
  paraphrase would.
- A blocked plan told the user to pass `--force`, which is unreachable from a menu — the command
  they ran may not even accept it. Interactively it now asks `Proceed anyway?`, defaulting to no;
  unattended it still points at the flag.
- Each prompt built its own terminal reader, and a reader holds the bytes it has read but not yet
  handed out. Typing ahead through a chain of prompts — browse, pick an action, answer — dropped
  everything typed before the next prompt opened. One reader per command now.
- Key columns were padded with `str_pad`, which counts bytes: a key holding a glyph or a
  Cyrillic word came out one column short per continuation byte and broke the alignment of
  everything under it.
- Reading a keystroke assumed one `fread` returned one key. Holding a key down, typing fast,
  pasting, or a terminal answering a query mid-stream all deliver several at once, and everything
  after the first was silently dropped. Input is now buffered and split key by key.

### Notes

- Interactivity is additive: passing coordinates, `--no-interaction`, a pipe, or a terminal
  without `stty` all take exactly the path they took before, so scripts and CI are unaffected.
  `extra:update` with no argument still means "everything installed" when unattended.
- `laravel/prompts` is deliberately not used: every version requires `symfony/console ^6.2`,
  and Evolution CMS 3.1 ships 5.4.

## [0.2.0] - 2026-08-07

### Fixed

- `extra:install` and `extra:update` could never run. Both declared their own `--version`, and
  Symfony defines that option on the console application; `Command::run()` merges the two
  definitions before parsing anything, so `InputDefinition::addOption()` threw
  `An option named "version" already exists` on every invocation, flag or no flag. The option is
  now `--use-version`. **Breaking:** scripts passing `--version` must be updated.
- The plan for a composer extra listed a `record <coordinate> as installed` step that nothing ever
  carried out — only `LegacyInstaller` applies `RecordWrite`. The step is gone; for a composer
  extra the manifest is the record, and `installedState()` reads it back. `extra:doctor` no longer
  looks wrong by reporting `0 record(s)` after a successful install.

### Added

- `CommandDefinitionTest` guards every command against option names Symfony reserves, both by
  reading the signature and by registering the command on a real `Application`. The existing tests
  missed the bug because they never went through `mergeApplicationDefinition()`.

## [0.1.1] - 2026-08-06

No behaviour changes. Released because the v0.1.0 tag was moved after publication and Packagist
still serves the commit it was first published from; this is the version to install.

### Added

- phpstan level 6 and php-cs-fixer, wired into `composer check` and CI.
- Issue and pull request templates, code of conduct.
- Release workflow that takes its notes from this file and fails when the tag has no entry.

### Changed

- README, SECURITY.md and CONTRIBUTING.md rewritten to drop the commentary and say what the tool
  does and where it will bite.
- Removed redundant guards that static analysis found: `is_array()` on values already known to be
  arrays, `array_values()` on lists.

### Fixed

- `ElementWriter::hasColumn()` called `getSchemaBuilder()` on `ConnectionInterface`, which does not
  declare it. It worked only because the concrete connection has the method.

## [0.1.0] - 2026-08-06

First release. Targets Evolution CMS CE 3.1.x.

### Added

- Commands: `extra:list`, `extra:install`, `extra:update`, `extra:remove`, `extra:info`,
  `extra:cache`, `extra:doctor`.
- Two installer adapters behind one interface: Composer packages of type `evolutioncms-*`, and
  legacy extras in the MODX Evolution Package format.
- Removal for both formats, including legacy, which has no uninstall of its own.
- Plan and apply split: operations are assembled in full before anything changes, which is what
  `--dry-run` prints.
- Install records tracking written files, created elements, applied SQL statements and the previous
  code of overwritten elements.
- Catalog built from a bundled snapshot, Packagist and GitHub organisations, with a file cache and
  `GITHUB_PAT` support.
- Per-extra compatibility status; anything not verified on Evolution CMS 3 needs `--force`.
- Bundled snapshot with 40 extras, 24 composer and 16 legacy.

### Known limitations

- The snapshot covers 16 of roughly 160 known legacy extras. The rest need
  `extra:cache --rebuild-snapshot` and a GitHub token.
- Every legacy entry ships as `unknown`; none has been verified on Evolution CMS 3 yet.
- Schema applied by a legacy extra is not rolled back on removal.

[Unreleased]: https://github.com/hkyss/evocms-extras/compare/v1.0.1...HEAD
[1.0.1]: https://github.com/hkyss/evocms-extras/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/hkyss/evocms-extras/compare/v0.2.0...v1.0.0
[0.2.0]: https://github.com/hkyss/evocms-extras/releases/tag/v0.2.0
[0.1.1]: https://github.com/hkyss/evocms-extras/releases/tag/v0.1.1
[0.1.0]: https://github.com/hkyss/evocms-extras/releases/tag/v0.1.0
