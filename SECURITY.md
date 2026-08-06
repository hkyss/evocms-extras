# Security

## Reporting

Report privately through [GitHub Security Advisories](https://github.com/hkyss/evocms-extras/security/advisories/new)
or by email to hkyss.work@protonmail.com. Please don't use a public issue.

Include the version, the extra or catalog source involved, and how to reproduce it. First reply
within a week.

Fixes go to the latest `0.x` release.

## What installing an extra does

Installing is deploying. Nothing here is sandboxed.

Composer extras register service providers that the core loads on every request. Legacy extras
write plugin and snippet code into your database, and Evolution executes it. Their
`install/setup.data.sql` runs against your database statement by statement with your table prefix
substituted in, unrestricted. Files under `assets/` are copied into the site tree, overwriting
what is there after backing it up to `.old`.

Archives come from `github.com/<org>/<repo>/archive/` over HTTPS. There is no signature check,
because the format carries nothing to check against.

`--dry-run` prints the whole plan before anything happens. Use it on anything you haven't
installed before. Extras with unconfirmed Evo 3 compatibility need `--force`, which is a
reasonable moment to go read the source.

## Trust boundaries

The catalog tells you what exists, not what is safe. The bundled snapshot is generated from public
sources, and its compatibility status only says whether an extra works, never whether it is
trustworthy.

Coordinates are validated for shape and nothing else. `extra:install org/repo` fetches from
whatever GitHub organisation you name, including ones you added to the config yourself.

`GITHUB_PAT` is read from the environment and sent only to `api.github.com` and `github.com`. It is
never written to the catalog cache or to an install record.

Install records store the previous source of overwritten elements so removal can restore it. They
hold code, not credentials, and want the same protection as the element tables.

## Hardening

Run `extra:doctor` after installing; it flags a world-writable manifest or provider directory. Keep
`core/` outside the web root as Evolution's own docs require. Try new extras on staging first —
`core/vendor/` is version-controlled in CE, so a Composer install leaves a reviewable diff.
