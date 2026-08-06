<?php

namespace hkyss\Extras\Console\Commands;

use hkyss\Extras\Installer\Intent;

class RemoveCommand extends AbstractExtraCommand
{
    protected $signature = 'extra:remove
        {coordinates?* : One or more vendor/package or org/repo coordinates}
        {--file= : Read coordinates from a file, one per line}
        {--dry-run : Show the plan and change nothing}
        {--force : Proceed even when the plan is blocked}
        {--continue-on-error : Keep going after a failure instead of stopping}';

    protected $description = 'Remove installed Evolution CMS extras';

    public function handle(): int
    {
        $coordinates = $this->coordinates((array) $this->argument('coordinates'), $this->option('file'));

        if ($coordinates === []) {
            $this->error('Nothing to remove. Pass a coordinate or use --file.');

            return self::FAILURE;
        }

        return $this->runMany($coordinates, Intent::Remove, '');
    }
}
