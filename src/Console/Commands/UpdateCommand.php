<?php

namespace hkyss\Extras\Console\Commands;

use hkyss\Extras\Installer\Intent;

class UpdateCommand extends AbstractExtraCommand
{
    protected $signature = 'extra:update
        {coordinates?* : Coordinates to update; omit to update everything installed}
        {--use-version= : Version, tag or branch to update to}
        {--file= : Read coordinates from a file, one per line}
        {--dry-run : Show the plan and change nothing}
        {--force : Proceed even when the plan is blocked}
        {--continue-on-error : Keep going after a failure instead of stopping}';

    protected $description = 'Update installed Evolution CMS extras';

    public function handle(): int
    {
        $coordinates = $this->coordinates((array) $this->argument('coordinates'), $this->option('file'));

        if ($coordinates === []) {
            $coordinates = $this->installedCoordinates();

            if ($coordinates === []) {
                $this->info('Nothing is installed.');

                return self::SUCCESS;
            }

            $this->line(sprintf('<fg=gray>updating %d installed extra(s)</>', count($coordinates)));
        }

        $version = (string) ($this->option('use-version') ?? '');

        if (count($coordinates) > 1 && $version !== '') {
            $this->error('--use-version applies to a single extra; update them one at a time to pin versions.');

            return self::FAILURE;
        }

        return $this->runMany($coordinates, Intent::Update, $version);
    }

    /** @return list<string> */
    private function installedCoordinates(): array
    {
        $installed = [];

        foreach ($this->catalog->all() as $extra) {
            if ($this->installers->stateOf($extra)->isInstalled()) {
                $installed[] = (string) $extra->coordinate();
            }
        }

        return $installed;
    }
}
