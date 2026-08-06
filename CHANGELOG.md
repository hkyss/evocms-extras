# Changelog

Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), versioning follows
[SemVer](https://semver.org/spec/v2.0.0.html). On `0.x` a minor version may break compatibility;
those cases are called out.

## [Unreleased]

### Added

- phpstan level 6 and php-cs-fixer, wired into `composer check` and CI.
- Issue and pull request templates, code of conduct.
- Release workflow that takes its notes from this file and fails when the tag has no entry.

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

[Unreleased]: https://github.com/hkyss/evocms-extras/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/hkyss/evocms-extras/releases/tag/v0.1.0
