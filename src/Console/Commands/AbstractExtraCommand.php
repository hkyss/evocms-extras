<?php

namespace hkyss\Extras\Console\Commands;

use hkyss\Extras\Catalog\Catalog;
use hkyss\Extras\Domain\Coordinate;
use hkyss\Extras\Domain\Extra;
use hkyss\Extras\Exceptions\ExtrasException;
use hkyss\Extras\Installer\InstallerRegistry;
use hkyss\Extras\Installer\InstallPlan;
use hkyss\Extras\Installer\Intent;
use hkyss\Extras\Installer\Outcome;
use hkyss\Extras\Installer\StepKind;
use Illuminate\Console\Command;

abstract class AbstractExtraCommand extends Command
{
    protected Catalog $catalog;
    protected InstallerRegistry $installers;

    public function __construct(Catalog $catalog, InstallerRegistry $installers)
    {
        parent::__construct();
        $this->catalog = $catalog;
        $this->installers = $installers;
    }

    protected function resolve(string $input): ?Extra
    {
        $coordinate = Coordinate::tryParse($input);

        if ($coordinate === null) {
            $this->error("Invalid coordinate '{$input}'. Expected vendor/package or org/repo.");

            return null;
        }

        $extra = $this->catalog->find($coordinate);

        if ($extra === null) {
            $this->error("'{$coordinate}' was not found in the catalog.");
            $this->reportCatalogProblems();
            $this->line("Try <info>extra:list --search={$coordinate->name()}</info> to see what is available.");

            return null;
        }

        return $extra;
    }

    protected function reportCatalogProblems(): void
    {
        $problems = $this->catalog->problems();

        if ($problems === []) {
            return;
        }

        $this->newLine();
        $this->warn('Some catalog sources did not answer:');

        foreach ($problems as $source => $reason) {
            $this->line("  <comment>{$source}</comment>: {$reason}");
        }
    }

    protected function renderPlan(InstallPlan $plan): void
    {
        $this->newLine();
        $this->line(sprintf(
            '<options=bold>%s</> %s  <fg=gray>[%s]</>',
            $plan->intent()->verb(),
            $plan->coordinate(),
            $plan->format()->label()
        ));

        if ($plan->fromVersion() !== '' || $plan->toVersion() !== '') {
            $this->line(sprintf(
                '  version: %s → %s',
                $plan->fromVersion() !== '' ? $plan->fromVersion() : '—',
                $plan->toVersion() !== '' ? $plan->toVersion() : '—'
            ));
        }

        $this->newLine();

        $grouped = [];

        foreach ($plan->steps() as $step) {
            if ($step->kind() === StepKind::RecordWrite && $step->get('archive_root') !== null) {
                continue;
            }

            $grouped[$step->kind()->group()][] = $step;
        }

        if ($grouped === []) {
            $this->line('  <fg=gray>nothing to do</>');
        }

        foreach ($grouped as $group => $steps) {
            $this->line("  <fg=cyan>{$group}</>");

            foreach (array_slice($steps, 0, 20) as $step) {
                $marker = $step->kind()->mutates() ? '·' : ' ';
                $this->line("    {$marker} " . $step->summary());
            }

            if (count($steps) > 20) {
                $this->line(sprintf('      <fg=gray>… and %d more</>', count($steps) - 20));
            }
        }

        foreach ($plan->warnings() as $warning) {
            $this->newLine();
            $this->warn('  ' . $warning);
        }
    }

    protected function passesBlockers(InstallPlan $plan, bool $force): bool
    {
        if (!$plan->isBlocked()) {
            return true;
        }

        $this->newLine();

        foreach ($plan->blockers() as $blocker) {
            $this->error('  ' . $blocker);
        }

        if ($force) {
            $this->warn('  proceeding anyway because --force was given');

            return true;
        }

        $this->newLine();
        $this->line('  Pass <info>--force</info> to proceed regardless.');

        return false;
    }

    protected function renderOutcome(Outcome $outcome): int
    {
        $this->newLine();

        if ($outcome->isSuccessful()) {
            $this->info($outcome->message());
        } else {
            $this->error($outcome->message());
        }

        foreach ($outcome->notes() as $note) {
            $this->line('  <comment>' . $note . '</comment>');
        }

        if (!$outcome->isSuccessful() && $outcome->output() !== []) {
            $this->newLine();
            $this->line('<fg=gray>composer output:</>');

            foreach ($outcome->output() as $line) {
                $this->line('  <fg=gray>' . $line . '</>');
            }
        }

        return $outcome->isSuccessful() ? self::SUCCESS : self::FAILURE;
    }

    protected function runOne(Extra $extra, Intent $intent, string $version, bool $dryRun, bool $force): int
    {
        try {
            $plan = $this->installers->for($extra)->plan($extra, $intent, $version);
        } catch (ExtrasException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->renderPlan($plan);

        if (!$this->passesBlockers($plan, $force)) {
            return self::FAILURE;
        }

        if ($dryRun) {
            $this->newLine();
            $this->line('<fg=gray>--dry-run: nothing was changed</>');

            return self::SUCCESS;
        }

        if ($plan->isEmpty()) {
            $this->newLine();
            $this->info('Nothing to do.');

            return self::SUCCESS;
        }

        try {
            return $this->renderOutcome($this->installers->for($extra)->apply($plan));
        } catch (ExtrasException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * @param list<string> $arguments
     * @return list<string>
     */
    protected function coordinates(array $arguments, ?string $file): array
    {
        $coordinates = array_values(array_filter(array_map('trim', $arguments), static fn ($c) => $c !== ''));

        if ($file === null || trim($file) === '') {
            return $coordinates;
        }

        if (!is_file($file)) {
            $this->error("List file '{$file}' does not exist.");

            return $coordinates;
        }

        foreach (preg_split('~\R~', (string) file_get_contents($file)) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $coordinates[] = $line;
        }

        return array_values(array_unique($coordinates));
    }

    /**
     * @param list<string> $coordinates
     */
    protected function runMany(array $coordinates, Intent $intent, string $version): int
    {
        $failed = 0;
        $continue = (bool) $this->option('continue-on-error');

        foreach ($coordinates as $coordinate) {
            $extra = $this->resolve($coordinate);

            if ($extra === null) {
                $failed++;

                if (!$continue) {
                    return self::FAILURE;
                }

                continue;
            }

            $status = $this->runOne(
                $extra,
                $intent,
                $version,
                (bool) $this->option('dry-run'),
                (bool) $this->option('force')
            );

            if ($status !== self::SUCCESS) {
                $failed++;

                if (!$continue) {
                    return $status;
                }
            }
        }

        if ($failed > 0) {
            $this->newLine();
            $this->error(sprintf('%d of %d failed.', $failed, count($coordinates)));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
