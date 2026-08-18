<?php

namespace hkyss\Extras\Installer;

use hkyss\Extras\Domain\Extra;
use hkyss\Extras\Domain\ExtraFormat;
use hkyss\Extras\Domain\InstalledState;

interface Installer
{
    public function supports(Extra $extra): bool;

    /** The format this installer handles, for entries the catalog cannot describe. */
    public function format(): ExtraFormat;

    /**
     * Builds the plan without touching the site; it may still hit the network.
     *
     * @throws \hkyss\Extras\Exceptions\ExtrasException when no plan can be built
     */
    public function plan(Extra $extra, Intent $intent, string $version = ''): InstallPlan;

    /** Executes a plan; makes no decisions of its own. */
    public function apply(InstallPlan $plan): Outcome;

    public function installedState(Extra $extra): InstalledState;

    /**
     * Everything this installer knows to be installed, read from its own record rather than
     * from the catalog, so the answer survives a catalog that is stale or unreachable.
     *
     * @return array<string, InstalledState> keyed by coordinate
     */
    public function installed(): array;
}
