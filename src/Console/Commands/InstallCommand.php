<?php

namespace hkyss\Extras\Console\Commands;

use hkyss\Extras\Domain\CompatibilityStatus;
use hkyss\Extras\Installer\Intent;

class InstallCommand extends AbstractExtraCommand
{
    protected $signature = 'extra:install
        {coordinates?* : One or more vendor/package or org/repo coordinates}
        {--use-version= : Version, tag or branch to install; defaults to the latest}
        {--file= : Read coordinates from a file, one per line}
        {--dry-run : Show the plan and change nothing}
        {--force : Proceed even when the plan is blocked}
        {--ignore-platform-reqs : Install anyway when the php or ext-* requirement is unmet}
        {--continue-on-error : Keep going after a failure instead of stopping}';

    protected $description = 'Install Evolution CMS extras';

    public function handle(): int
    {
        $coordinates = $this->coordinates((array) $this->argument('coordinates'), $this->option('file'));
        $version = (string) ($this->option('use-version') ?? '');
        $picked = false;

        if ($coordinates === [] && $this->isInteractive()) {
            $chosen = $this->pick();

            if ($chosen === null) {
                return self::SUCCESS;
            }

            $coordinates = $chosen;
            $picked = true;
        }

        if ($coordinates === []) {
            return $this->bail('Nothing to install. Pass a coordinate or use --file.');
        }

        if (count($coordinates) > 1 && $version !== '') {
            return $this->bail(
                '--use-version applies to a single extra; install them one at a time to pin versions.'
            );
        }

        if ($picked && count($coordinates) === 1 && $version === '') {
            $extra = $this->resolve($coordinates[0]);

            if ($extra === null) {
                return self::FAILURE;
            }

            $version = $this->chooseVersion($extra);
        }

        return $this->runMany($coordinates, Intent::Install, $version);
    }

    /** @return list<string>|null */
    private function pick(): ?array
    {
        $rows = [];

        foreach ($this->spin('loading catalog', fn () => $this->catalog->all()) as $extra) {
            if ($this->installers->stateOf($extra)->isInstalled()) {
                continue;
            }

            $rows[] = $this->optionFor(
                $extra,
                $extra->compatibility() === CompatibilityStatus::Verified
                    ? ''
                    : $extra->compatibility()->label() . '  ' . $this->ui()->truncate($extra->description(), 36)
            );
        }

        if ($rows === []) {
            $this->ui()->note('ok', 'Everything in the catalog is already installed.');

            return null;
        }

        return $this->choose('Install which extra?', $rows);
    }
}
