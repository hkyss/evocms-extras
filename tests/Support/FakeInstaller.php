<?php

namespace hkyss\Extras\Tests\Support;

use hkyss\Extras\Domain\Extra;
use hkyss\Extras\Domain\ExtraFormat;
use hkyss\Extras\Domain\InstalledState;
use hkyss\Extras\Installer\EnumeratesInstalled;
use hkyss\Extras\Installer\Installer;
use hkyss\Extras\Installer\InstallPlan;
use hkyss\Extras\Installer\Intent;
use hkyss\Extras\Installer\Outcome;

/** Stands in for the two real installers where a test only needs to know what is installed. */
class FakeInstaller implements Installer, EnumeratesInstalled
{
    private ExtraFormat $format;
    /** @var array<string, InstalledState> */
    private array $installed;

    /** @param array<string, InstalledState> $installed */
    public function __construct(ExtraFormat $format, array $installed = [])
    {
        $this->format = $format;
        $this->installed = $installed;
    }

    public function supports(Extra $extra): bool
    {
        return $extra->format() === $this->format;
    }

    public function plan(Extra $extra, Intent $intent, string $version = ''): InstallPlan
    {
        return new InstallPlan($extra->coordinate(), $this->format, $intent);
    }

    public function apply(InstallPlan $plan): Outcome
    {
        return Outcome::noop('nothing to do');
    }

    public function installedState(Extra $extra): InstalledState
    {
        return $this->installed[(string) $extra->coordinate()] ?? InstalledState::absent();
    }

    public function format(): ExtraFormat
    {
        return $this->format;
    }

    /** @return array<string, InstalledState> */
    public function installed(): array
    {
        return $this->installed;
    }
}
