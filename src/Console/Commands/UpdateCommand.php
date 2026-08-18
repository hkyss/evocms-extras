<?php

namespace hkyss\Extras\Console\Commands;

use hkyss\Extras\Domain\Extra;
use hkyss\Extras\Installer\Intent;

class UpdateCommand extends AbstractExtraCommand
{
    protected $signature = 'extra:update
        {coordinates?* : Coordinates to update; omit to update everything installed}
        {--use-version= : Version, tag or branch to update to}
        {--file= : Read coordinates from a file, one per line}
        {--dry-run : Show the plan and change nothing}
        {--force : Proceed even when the plan is blocked}
        {--ignore-platform-reqs : Install anyway when the php or ext-* requirement is unmet}
        {--continue-on-error : Keep going after a failure instead of stopping}';

    protected $description = 'Update installed Evolution CMS extras';

    public function handle(): int
    {
        $coordinates = $this->coordinates((array) $this->argument('coordinates'), $this->option('file'));

        if ($coordinates === []) {
            $installed = $this->spin('reading installed extras', fn () => $this->installed());

            if ($installed === []) {
                $this->ui()->note('ok', 'Nothing is installed.');

                return self::SUCCESS;
            }

            if ($this->isInteractive()) {
                $coordinates = $this->choose('Update which extras?', array_map(
                    fn (Extra $extra): array => $this->optionFor(
                        $extra,
                        'installed ' . $this->installers->stateOf($extra)->describe()
                    ),
                    $installed
                ));

                if ($coordinates === null) {
                    return self::SUCCESS;
                }
            } else {
                $coordinates = array_map(static fn (Extra $e): string => (string) $e->coordinate(), $installed);

                $this->ui()->write($this->ui()->dim(sprintf(
                    'updating %d installed extra(s)',
                    count($coordinates)
                )));
            }
        }

        $version = (string) ($this->option('use-version') ?? '');

        if (count($coordinates) > 1 && $version !== '') {
            return $this->bail(
                '--use-version applies to a single extra; update them one at a time to pin versions.'
            );
        }

        return $this->runMany($coordinates, Intent::Update, $version);
    }

    /** @return list<Extra> */
    private function installed(): array
    {
        $installed = [];

        foreach ($this->catalog->all() as $extra) {
            if ($this->installers->stateOf($extra)->isInstalled()) {
                $installed[] = $extra;
            }
        }

        return $installed;
    }
}
