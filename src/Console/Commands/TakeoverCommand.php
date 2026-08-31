<?php

namespace hkyss\Extras\Console\Commands;

use hkyss\Extras\Catalog\Catalog;
use hkyss\Extras\Installer\InstallerRegistry;
use hkyss\Extras\Record\TakeoverRecord;
use hkyss\Extras\Record\TakeoverRecordStore;
use hkyss\Extras\Takeover\Takeover;
use hkyss\Extras\Takeover\TakeoverAction;
use hkyss\Extras\Takeover\TakeoverPlan;
use hkyss\Extras\Takeover\TakeoverPlanner;

class TakeoverCommand extends AbstractExtraCommand
{
    protected $signature = 'extra:takeover
        {--restore : Switch back on what a takeover disabled and remove what it installed}
        {--dry-run : Show the plan and change nothing}';

    protected $description = 'Put what the legacy manager left on the site under this tool, and give it back';

    private TakeoverPlanner $planner;
    private Takeover $takeover;
    private TakeoverRecordStore $records;

    public function __construct(
        Catalog $catalog,
        InstallerRegistry $installers,
        TakeoverPlanner $planner,
        Takeover $takeover,
        TakeoverRecordStore $records
    ) {
        parent::__construct($catalog, $installers);
        $this->planner = $planner;
        $this->takeover = $takeover;
        $this->records = $records;
    }

    public function handle(): int
    {
        return $this->option('restore') ? $this->giveBack() : $this->take();
    }

    private function take(): int
    {
        $ui = $this->ui();
        $ui->heading('extra:takeover', 'what the legacy manager left, under this one');

        $plan = $this->spin('reading the site and the catalog', fn () => $this->planner->plan());

        $this->reportCatalogProblems();
        $this->renderSteps($plan);

        if ($plan->isEmpty()) {
            $ui->blank();
            $ui->banner(true, 'Nothing to take over.');

            return self::SUCCESS;
        }

        if ((bool) $this->option('dry-run')) {
            $ui->blank();
            $ui->write($ui->dim('--dry-run: nothing was changed'));

            return self::SUCCESS;
        }

        if ($this->isInteractive() && !$this->confirmStep('Apply this plan?')) {
            $ui->note('warn', 'Cancelled, nothing was changed.');

            return self::SUCCESS;
        }

        return $this->renderOutcome($this->spin('taking over', fn () => $this->takeover->apply($plan)));
    }

    private function giveBack(): int
    {
        $ui = $this->ui();
        $ui->heading('extra:takeover --restore', 'the site as a takeover found it');

        $records = $this->records->all();

        if ($records === []) {
            $ui->blank();
            $ui->banner(true, 'Nothing was taken over; the site is as it was.');

            return self::SUCCESS;
        }

        $this->renderRecords($records);

        if ((bool) $this->option('dry-run')) {
            $ui->blank();
            $ui->write($ui->dim('--dry-run: nothing was changed'));

            return self::SUCCESS;
        }

        if ($this->isInteractive() && !$this->confirmStep('Give all of it back?')) {
            $ui->note('warn', 'Cancelled, nothing was changed.');

            return self::SUCCESS;
        }

        return $this->renderOutcome($this->spin('restoring', fn () => $this->takeover->restore()));
    }

    private function renderSteps(TakeoverPlan $plan): void
    {
        $ui = $this->ui();

        foreach (TakeoverAction::cases() as $action) {
            $steps = $plan->of($action);

            if ($steps === []) {
                continue;
            }

            $ui->blank();
            $ui->section($action->value);
            $ui->write('  ' . $ui->dim($action->explain()));

            foreach ($steps as $index => $step) {
                $ui->treeItem($step->summary(), $index === count($steps) - 1, $action !== TakeoverAction::Skip);
            }
        }
    }

    /** @param list<TakeoverRecord> $records */
    private function renderRecords(array $records): void
    {
        $ui = $this->ui();
        $lines = [];

        foreach ($records as $record) {
            $coordinate = $record->installed();

            if ($coordinate !== null) {
                $lines[] = 'remove ' . $coordinate;
            }

            foreach ($record->elementList() as $element) {
                $lines[] = 'switch ' . $element->label() . ' back on';
            }
        }

        $ui->blank();
        $ui->section('restore');

        foreach ($lines as $index => $line) {
            $ui->treeItem($line, $index === count($lines) - 1, true);
        }
    }
}
