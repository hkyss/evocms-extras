<?php

namespace hkyss\Extras\Takeover;

use hkyss\Extras\Domain\Extra;
use hkyss\Extras\Exceptions\ExtrasException;
use hkyss\Extras\Installer\HoldsArchives;
use hkyss\Extras\Installer\InstallerRegistry;
use hkyss\Extras\Installer\Intent;
use hkyss\Extras\Installer\Outcome;
use hkyss\Extras\Record\TakeoverRecord;
use hkyss\Extras\Record\TakeoverRecordStore;
use hkyss\Extras\Support\SiteCache;

/** Applies a takeover, and gives the site back everything one did. */
class Takeover
{
    private InstallerRegistry $installers;
    private SiteElements $site;
    private TakeoverRecordStore $records;
    private SiteCache $cache;

    public function __construct(
        InstallerRegistry $installers,
        SiteElements $site,
        TakeoverRecordStore $records,
        ?SiteCache $cache = null
    ) {
        $this->installers = $installers;
        $this->site = $site;
        $this->records = $records;
        $this->cache = $cache ?? new SiteCache();
    }

    public function apply(TakeoverPlan $plan): Outcome
    {
        if (!$this->records->isAvailable()) {
            return Outcome::failure(
                'The takeover record table is missing; run "php artisan migrate" first. '
                . 'Without it nothing done here could be given back.'
            );
        }

        $steps = $plan->actionable();

        if ($steps === []) {
            return Outcome::noop('Nothing to take over.');
        }

        $done = [];
        $notes = [];
        $switched = 0;
        $installed = 0;
        $left = 0;

        foreach ($steps as $step) {
            $disabled = [];

            foreach ($step->disabled() as $element) {
                if (!$this->site->disable($element)) {
                    $notes[] = 'not there any more: ' . $element->label();

                    continue;
                }

                $disabled[] = $element;
            }

            // Written before the install rather than after it: a run that dies inside Composer
            // still leaves the row that switches the site back on.
            $record = $this->records->put($step, $disabled);

            if ($record !== null) {
                $done[] = $record;
            }

            if (!$step->action()->installs()) {
                $switched += count($disabled);

                continue;
            }

            $outcome = $this->install($step);

            if (!$outcome->isSuccessful()) {
                return $this->abandon($step, $done, array_merge($notes, $outcome->notes()), $outcome);
            }

            $notes = array_merge($notes, $outcome->notes());

            // Nothing was attempted, so this row is left as it was rather than taken over: the
            // rows go back on, the ledger forgets it, and the rest of the plan carries on.
            if ($outcome->isNoop()) {
                $notes[] = 'left as it is: ' . $step->summary() . ' — ' . $outcome->message();
                $left++;

                if ($record !== null) {
                    $notes = array_merge($notes, $this->undo([$record])->notes());
                    $done = array_values(array_filter(
                        $done,
                        static fn (TakeoverRecord $kept) => $kept !== $record
                    ));
                }

                continue;
            }

            $switched += count($disabled);
            $installed++;
        }

        $this->cache->clear();

        return Outcome::success(
            sprintf(
                '%d element(s) switched off, %d extra(s) now installed through this tool%s',
                $switched,
                $installed,
                $left > 0 ? sprintf(', %d left as it was', $left) : ''
            ),
            $notes
        );
    }

    public function restore(): Outcome
    {
        $records = $this->records->all();

        if ($records === []) {
            return Outcome::noop('Nothing was taken over; the site is as it was.');
        }

        $outcome = $this->undo($records);
        $this->cache->clear();

        return $outcome;
    }

    /**
     * One extra failing is the whole takeover failing: the site is left as it was found, and
     * whatever this run had already changed goes back before it says so.
     *
     * @param list<TakeoverRecord> $done
     * @param list<string>         $notes
     */
    private function abandon(TakeoverStep $step, array $done, array $notes, Outcome $failure): Outcome
    {
        $rollback = $this->undo(array_reverse($done));
        $this->cache->clear();

        return Outcome::failure(
            sprintf('%s could not be installed, so nothing was taken over', $step->coordinate()),
            array_merge($notes, [$failure->message()], $rollback->notes()),
            $failure->output()
        );
    }

    /**
     * @param list<TakeoverRecord> $records
     */
    private function undo(array $records): Outcome
    {
        $removed = 0;
        $enabled = 0;
        $notes = [];
        $failed = 0;

        foreach ($records as $record) {
            $extra = $this->extraOf($record);

            // An extra Composer or the records no longer carry is already where a restore
            // wants it, and asking its installer to remove it again only fails.
            if ($extra !== null && $this->installers->stateOf($extra)->isInstalled()) {
                $outcome = $this->through($extra, Intent::Remove, '');

                if (!$outcome->isSuccessful()) {
                    // The record stays: it is the only thing that knows the site is mid-way,
                    // and a second run has to be able to finish what this one could not.
                    $notes[] = $record->coordinate . ' could not be removed: ' . $outcome->message();
                    $failed++;

                    continue;
                }

                $removed++;
            }

            foreach ($record->elementList() as $element) {
                if ($this->site->enable($element->type(), $element->id())) {
                    $enabled++;

                    continue;
                }

                $notes[] = 'gone, nothing to switch back on: ' . $element->label();
            }

            $this->records->forget($record);
        }

        $message = sprintf('%d element(s) switched back on, %d extra(s) removed', $enabled, $removed);

        return $failed === 0
            ? Outcome::success($message, $notes)
            : Outcome::failure($message . sprintf(', %d left as it is', $failed), $notes);
    }

    private function extraOf(TakeoverRecord $record): ?Extra
    {
        $coordinate = $record->installed();
        $format = $record->format();

        return $coordinate !== null && $format !== null ? new Extra($coordinate, $format) : null;
    }

    private function install(TakeoverStep $step): Outcome
    {
        $extra = $step->extra();

        if ($extra === null) {
            return Outcome::noop('nothing to install');
        }

        return $this->through($extra, Intent::Install, $step->version());
    }

    private function through(Extra $extra, Intent $intent, string $version): Outcome
    {
        $installer = null;

        try {
            $installer = $this->installers->for($extra);
            $plan = $installer->plan($extra, $intent, $version);

            if ($plan->isForbidden()) {
                return Outcome::failure(implode('; ', $plan->forbidden()));
            }

            // A blocked plan with nothing in it is blocked over something no force gets past —
            // an archive that turned out not to hold a package has no steps to apply. Nothing
            // was attempted, so it is not a failed takeover; it is one row fewer in it.
            if ($plan->isEmpty() && $plan->blockers() !== []) {
                return Outcome::noop(implode('; ', $plan->blockers()));
            }

            $outcome = $installer->apply($plan);

            // Blockers are not a reason to stop here the way they are for an install somebody
            // asked for: what the site is already running has answered the compatibility
            // question by running. They are still said out loud.
            foreach ($plan->blockers() as $blocker) {
                $outcome = $outcome->withNote('done regardless: ' . $blocker);
            }

            return $outcome;
        } catch (ExtrasException $e) {
            return Outcome::failure($e->getMessage());
        } finally {
            if ($installer instanceof HoldsArchives) {
                $installer->discardArchives();
            }
        }
    }
}
