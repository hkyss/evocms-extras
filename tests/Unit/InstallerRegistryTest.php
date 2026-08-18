<?php

namespace hkyss\Extras\Tests\Unit;

use hkyss\Extras\Domain\Extra;
use hkyss\Extras\Domain\ExtraFormat;
use hkyss\Extras\Domain\InstalledState;
use hkyss\Extras\Installer\EnumeratesInstalled;
use hkyss\Extras\Installer\Installer;
use hkyss\Extras\Installer\InstallerRegistry;
use hkyss\Extras\Installer\InstallPlan;
use hkyss\Extras\Installer\Intent;
use hkyss\Extras\Installer\Outcome;
use PHPUnit\Framework\TestCase;

class InstallerRegistryTest extends TestCase
{
    /** @param array<string, InstalledState> $installed */
    private function installer(ExtraFormat $format, array $installed): Installer
    {
        return new class ($format, $installed) implements Installer, EnumeratesInstalled {
            /** @param array<string, InstalledState> $installed */
            public function __construct(private ExtraFormat $format, private array $installed)
            {
            }

            public function supports(Extra $extra): bool
            {
                return $extra->format() === $this->format;
            }

            public function format(): ExtraFormat
            {
                return $this->format;
            }

            public function plan(Extra $extra, Intent $intent, string $version = ''): InstallPlan
            {
                throw new \LogicException('not used');
            }

            public function apply(InstallPlan $plan): Outcome
            {
                throw new \LogicException('not used');
            }

            public function installedState(Extra $extra): InstalledState
            {
                return $this->installed[(string) $extra->coordinate()] ?? InstalledState::absent();
            }

            /** @return array<string, InstalledState> */
            public function installed(): array
            {
                return $this->installed;
            }
        };
    }

    public function testEveryInstallerContributesAndTheResultIsSorted(): void
    {
        $registry = new InstallerRegistry([
            $this->installer(ExtraFormat::Composer, [
                'vendor/zeta' => InstalledState::present('1.0.0', '^1.0'),
            ]),
            $this->installer(ExtraFormat::Legacy, [
                'org/alpha' => InstalledState::present('master'),
            ]),
        ]);

        $installed = $registry->installed();

        self::assertSame(['org/alpha', 'vendor/zeta'], array_keys($installed));
        self::assertSame(ExtraFormat::Legacy, $installed['org/alpha']['format']);
        self::assertSame(ExtraFormat::Composer, $installed['vendor/zeta']['format']);
        self::assertSame('1.0.0', $installed['vendor/zeta']['state']->version());
    }

    public function testNothingInstalled(): void
    {
        $registry = new InstallerRegistry([
            $this->installer(ExtraFormat::Composer, []),
            $this->installer(ExtraFormat::Legacy, []),
        ]);

        self::assertSame([], $registry->installed());
    }

    public function testARegistryWithoutInstallersAnswersEmpty(): void
    {
        self::assertSame([], (new InstallerRegistry())->installed());
    }

    /**
     * The enumeration was added after 1.0.0's interface was settled, so it lives in its own
     * interface: an installer written against Installer alone still works, it simply
     * contributes nothing to the installed list.
     */
    public function testAnInstallerThatCannotEnumerateIsSkippedRatherThanFatal(): void
    {
        $plain = new class () implements Installer {
            public function supports(Extra $extra): bool
            {
                return true;
            }

            public function plan(Extra $extra, Intent $intent, string $version = ''): InstallPlan
            {
                throw new \LogicException('not used');
            }

            public function apply(InstallPlan $plan): Outcome
            {
                throw new \LogicException('not used');
            }

            public function installedState(Extra $extra): InstalledState
            {
                return InstalledState::absent();
            }
        };

        $registry = new InstallerRegistry([
            $plain,
            $this->installer(ExtraFormat::Legacy, ['org/alpha' => InstalledState::present('master')]),
        ]);

        self::assertSame(['org/alpha'], array_keys($registry->installed()));
    }
}
