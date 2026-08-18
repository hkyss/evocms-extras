<?php

namespace hkyss\Extras\Console\Commands;

use hkyss\Extras\Catalog\Catalog;
use hkyss\Extras\Console\ExtraPresenter;
use hkyss\Extras\Console\Prompt\ConfirmPrompt;
use hkyss\Extras\Console\Prompt\SelectPrompt;
use hkyss\Extras\Console\Prompt\Spinner;
use hkyss\Extras\Console\Tty;
use hkyss\Extras\Console\Ui;
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
    protected const STEPS_SHOWN = 20;

    protected Catalog $catalog;
    protected InstallerRegistry $installers;

    private ?Ui $ui = null;

    private ?ExtraPresenter $presenter = null;

    private ?Tty $tty = null;

    public function __construct(Catalog $catalog, InstallerRegistry $installers)
    {
        parent::__construct();
        $this->catalog = $catalog;
        $this->installers = $installers;
    }

    protected function ui(): Ui
    {
        return $this->ui ??= new Ui($this->output);
    }

    protected function isInteractive(): bool
    {
        return $this->input->isInteractive()
            && !Tty::runsUnattended()
            && Tty::isAvailable()
            && $this->ui()->isDecorated();
    }

    protected function tty(): Tty
    {
        return $this->tty ??= new Tty();
    }

    /**
     * @param list<array{value:string,label?:string,hint?:string,search?:string}> $rows
     * @return list<string>|null null when the user backed out
     */
    protected function choose(string $title, array $rows, bool $multiple = true): ?array
    {
        return (new SelectPrompt($this->ui(), $this->tty()))->open($title, $rows, $multiple);
    }

    /** @return array{value:string,label:string,hint:string,search:string} */
    protected function optionFor(Extra $extra, string $hint = ''): array
    {
        return $this->presenter()->option($extra, $hint);
    }

    protected function chooseVersion(Extra $extra): string
    {
        $versions = $extra->versions();

        if (count($versions) < 2) {
            return '';
        }

        $rows = [[
            'value' => '',
            'label' => 'latest',
            'hint' => $extra->defaultVersion(),
            'search' => 'latest default',
        ]];

        foreach ($versions as $version) {
            $rows[] = ['value' => $version, 'label' => $version, 'hint' => '', 'search' => mb_strtolower($version)];
        }

        $chosen = $this->choose('Version of ' . $extra->coordinate(), $rows, false);

        return $chosen === null ? '' : ($chosen[0] ?? '');
    }

    protected function confirmStep(string $question, bool $default = true): bool
    {
        return (new ConfirmPrompt($this->ui(), $this->tty()))->ask($question, $default);
    }

    /**
     * @template T
     * @param callable(): T $work
     * @return T
     */
    protected function spin(string $label, callable $work)
    {
        if (!$this->isInteractive()) {
            return $work();
        }

        return (new Spinner($this->ui()))->spin($label, $work);
    }

    protected function presenter(): ExtraPresenter
    {
        return $this->presenter ??= new ExtraPresenter($this->ui());
    }

    /**
     * Composer's own flag name, and the escape hatch the platform block needs: the constraint
     * comes from the catalog, which can be stale or simply wrong, and a check that cannot be
     * overridden at all would make a bad catalog entry unfixable from here.
     *
     * Commands that never meet a platform requirement do not declare it.
     */
    protected function ignoresPlatformRequirements(): bool
    {
        return $this->getDefinition()->hasOption('ignore-platform-reqs')
            && (bool) $this->option('ignore-platform-reqs');
    }

    protected function bail(string $message): int
    {
        $this->ui()->note('fail', $message);

        return self::FAILURE;
    }

    protected function resolve(string $input): ?Extra
    {
        $ui = $this->ui();
        $coordinate = Coordinate::tryParse($input);

        if ($coordinate === null) {
            $ui->note('fail', "Invalid coordinate '{$input}'. Expected vendor/package or org/repo.");

            return null;
        }

        $extra = $this->catalog->find($coordinate);

        if ($extra === null) {
            $ui->note('fail', "'{$coordinate}' was not found in the catalog.");
            $this->reportCatalogProblems();
            $ui->blank();
            $ui->write('  Try <info>extra:list --search=' . $coordinate->name() . '</info> to see what is available.');

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

        $ui = $this->ui();
        $ui->blank();
        $ui->section('sources that did not answer');

        $checks = [];

        foreach ($problems as $source => $reason) {
            $checks[] = ['level' => 'warn', 'name' => (string) $source, 'detail' => $reason];
        }

        $ui->checks($checks);
    }

    protected function renderPlan(InstallPlan $plan): void
    {
        $ui = $this->ui();

        $ui->blank();
        $ui->write(sprintf(
            '%s %s  %s',
            $ui->strong($plan->intent()->verb()),
            $plan->coordinate(),
            $ui->dim('[' . $plan->format()->label() . ']')
        ));

        if ($plan->fromVersion() !== '' || $plan->toVersion() !== '') {
            $ui->details([[
                'version',
                ($plan->fromVersion() !== '' ? $plan->fromVersion() : $ui->absent())
                    . ' ' . $ui->dim($ui->glyph('arrow')) . ' '
                    . ($plan->toVersion() !== '' ? $plan->toVersion() : $ui->absent()),
            ]]);
        }

        $ui->blank();

        $grouped = [];

        foreach ($plan->steps() as $step) {
            if ($step->kind() === StepKind::RecordWrite && $step->get('archive_root') !== null) {
                continue;
            }

            $grouped[$step->kind()->group()][] = $step;
        }

        if ($grouped === []) {
            $ui->write('  ' . $ui->dim('nothing to do'));
        }

        foreach ($grouped as $group => $steps) {
            $ui->section($group);

            $shown = array_slice($steps, 0, self::STEPS_SHOWN);
            $hidden = count($steps) - count($shown);

            foreach ($shown as $index => $step) {
                $ui->treeItem(
                    $step->summary(),
                    $hidden === 0 && $index === count($shown) - 1,
                    $step->kind()->mutates()
                );
            }

            if ($hidden > 0) {
                $ui->write('  ' . $ui->dim(sprintf(
                    '%s %s and %d more',
                    $ui->glyph('corner'),
                    $ui->glyph('ellipsis'),
                    $hidden
                )));
            }
        }

        foreach ($plan->warnings() as $warning) {
            $ui->blank();
            $ui->note('warn', $warning, 2);
        }
    }

    protected function passesBlockers(InstallPlan $plan, bool $force): bool
    {
        if (!$plan->isBlocked()) {
            return true;
        }

        $ui = $this->ui();
        $ui->blank();

        foreach ($plan->forbidden() as $reason) {
            $ui->note('fail', $reason, 2);
        }

        foreach ($plan->blockers() as $blocker) {
            $ui->note('fail', $blocker, 2);
        }

        if ($plan->isForbidden()) {
            if ($this->ignoresPlatformRequirements()) {
                $ui->note('warn', 'proceeding anyway because --ignore-platform-reqs was given', 2);

                return true;
            }

            $ui->blank();
            $ui->write('  ' . $ui->dim('--force does not cover this; Composer would refuse for the same reason.'));
            $ui->write('  Pass <info>--ignore-platform-reqs</info> if the catalog is wrong about it.');

            return false;
        }

        if ($force) {
            $ui->note('warn', 'proceeding anyway because --force was given', 2);

            return true;
        }

        $ui->blank();

        if ($this->isInteractive()) {
            return $this->confirmStep('Proceed anyway?', false);
        }

        $ui->write('  Pass <info>--force</info> to proceed regardless.');

        return false;
    }

    protected function renderOutcome(Outcome $outcome): int
    {
        $ui = $this->ui();
        $ui->banner($outcome->isSuccessful(), $outcome->message());

        foreach ($outcome->notes() as $note) {
            $ui->write('  <comment>' . $note . '</comment>');
        }

        if (!$outcome->isSuccessful() && $outcome->output() !== []) {
            $ui->blank();
            $ui->section('composer output');

            foreach ($outcome->output() as $line) {
                $ui->write('  ' . $ui->dim($line));
            }
        }

        return $outcome->isSuccessful() ? self::SUCCESS : self::FAILURE;
    }

    protected function runOne(Extra $extra, Intent $intent, string $version, bool $dryRun, bool $force): int
    {
        $ui = $this->ui();

        try {
            $plan = $this->installers->for($extra)->plan($extra, $intent, $version);
        } catch (ExtrasException $e) {
            return $this->bail($e->getMessage());
        }

        $this->renderPlan($plan);

        if (!$this->passesBlockers($plan, $force)) {
            return self::FAILURE;
        }

        if ($dryRun) {
            $ui->blank();
            $ui->write($ui->dim('--dry-run: nothing was changed'));

            return self::SUCCESS;
        }

        if ($plan->isEmpty()) {
            $ui->banner(true, 'Nothing to do.');

            return self::SUCCESS;
        }

        if ($this->isInteractive() && !$this->confirmStep('Apply this plan?')) {
            $ui->note('warn', 'Cancelled, nothing was changed.');

            return self::SUCCESS;
        }

        try {
            return $this->renderOutcome($this->spin(
                'applying ' . $plan->coordinate(),
                fn () => $this->installers->for($extra)->apply($plan)
            ));
        } catch (ExtrasException $e) {
            return $this->bail($e->getMessage());
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
            $this->ui()->note('fail', "List file '{$file}' does not exist.");

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

    /** @param list<string> $coordinates */
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
            $this->ui()->banner(false, sprintf('%d of %d failed.', $failed, count($coordinates)));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
