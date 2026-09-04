<?php

namespace hkyss\Extras\Installer;

use hkyss\Extras\Domain\Extra;
use hkyss\Extras\Domain\InstalledState;

interface Installer
{
    public function supports(Extra $extra): bool;

    /**
     * @throws \hkyss\Extras\Exceptions\ExtrasException when no plan can be built
     */
    public function plan(Extra $extra, Intent $intent, string $version = ''): InstallPlan;

    /** Executes a plan; makes no decisions of its own. */
    public function apply(InstallPlan $plan): Outcome;

    public function installedState(Extra $extra): InstalledState;
}
