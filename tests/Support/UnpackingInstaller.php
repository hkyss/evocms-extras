<?php

namespace hkyss\Extras\Tests\Support;

use hkyss\Extras\Domain\Extra;
use hkyss\Extras\Domain\ExtraFormat;
use hkyss\Extras\Domain\InstalledState;
use hkyss\Extras\Exceptions\ExtrasException;
use hkyss\Extras\Installer\HoldsArchives;
use hkyss\Extras\Installer\Installer;
use hkyss\Extras\Installer\InstallPlan;
use hkyss\Extras\Installer\Intent;
use hkyss\Extras\Installer\Outcome;
use hkyss\Extras\Installer\StepKind;

/** Unpacks into the system temp directory to plan, the way the legacy installer does. */
class UnpackingInstaller implements Installer, HoldsArchives
{
    public int $discarded = 0;
    public int $applied = 0;

    private string $root;
    private string $blocker = '';
    private bool $failsWhilePlanning = false;
    private bool $nothingToDo = false;
    /** @var list<string> */
    private array $unpacked = [];
    private int $fetched = 0;

    public function __construct()
    {
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'extras-unpacked-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0775, true);
    }

    public function blockWith(string $reason): void
    {
        $this->blocker = $reason;
    }

    public function failWhilePlanning(): void
    {
        $this->failsWhilePlanning = true;
    }

    public function planNothing(): void
    {
        $this->nothingToDo = true;
    }

    /** @return list<string> */
    public function leftovers(): array
    {
        return array_map('basename', (array) glob($this->root . DIRECTORY_SEPARATOR . '*'));
    }

    public function remove(): void
    {
        foreach ((array) glob($this->root . DIRECTORY_SEPARATOR . '*') as $directory) {
            @rmdir((string) $directory);
        }

        @rmdir($this->root);
    }

    public function supports(Extra $extra): bool
    {
        return $extra->format() === ExtraFormat::Legacy;
    }

    public function plan(Extra $extra, Intent $intent, string $version = ''): InstallPlan
    {
        $directory = $this->root . DIRECTORY_SEPARATOR . 'archive-' . ++$this->fetched;
        mkdir($directory, 0775, true);
        $this->unpacked[] = $directory;

        if ($this->failsWhilePlanning) {
            throw new ExtrasException("'{$extra->coordinate()}' is not a MODX Evolution Package");
        }

        $plan = new InstallPlan($extra->coordinate(), ExtraFormat::Legacy, $intent, $version);

        if ($this->blocker !== '') {
            $plan->block($this->blocker);
        }

        if (!$this->nothingToDo) {
            $plan->step(StepKind::FileCopy, 'assets/snippets/thing/thing.php');
        }

        return $plan;
    }

    public function apply(InstallPlan $plan): Outcome
    {
        $this->applied++;

        return Outcome::success((string) $plan->coordinate() . ' installed');
    }

    public function installedState(Extra $extra): InstalledState
    {
        return InstalledState::absent();
    }

    public function discardArchives(): void
    {
        $this->discarded++;

        foreach ($this->unpacked as $directory) {
            @rmdir($directory);
        }

        $this->unpacked = [];
    }
}
