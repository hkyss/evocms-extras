<?php

namespace hkyss\Extras\Console\Commands;

use hkyss\Extras\Installer\Intent;

class InstallCommand extends AbstractExtraCommand
{
    protected $signature = 'extra:install
        {coordinates?* : One or more vendor/package or org/repo coordinates}
        {--version= : Version, tag or branch to install; defaults to the latest}
        {--file= : Read coordinates from a file, one per line}
        {--dry-run : Show the plan and change nothing}
        {--force : Proceed even when the plan is blocked}
        {--continue-on-error : Keep going after a failure instead of stopping}';

    protected $description = 'Install Evolution CMS extras';

    public function handle(): int
    {
        $coordinates = $this->coordinates((array) $this->argument('coordinates'), $this->option('file'));

        if ($coordinates === []) {
            $this->error('Nothing to install. Pass a coordinate or use --file.');

            return self::FAILURE;
        }

        $version = (string) ($this->option('version') ?? '');

        if (count($coordinates) > 1 && $version !== '') {
            $this->error('--version applies to a single extra; install them one at a time to pin versions.');

            return self::FAILURE;
        }

        return $this->runMany($coordinates, Intent::Install, $version);
    }
}
