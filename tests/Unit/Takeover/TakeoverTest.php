<?php

namespace hkyss\Extras\Tests\Unit\Takeover;

use hkyss\Extras\Domain\Coordinate;
use hkyss\Extras\Domain\Extra;
use hkyss\Extras\Domain\ExtraFormat;
use hkyss\Extras\Domain\InstalledState;
use hkyss\Extras\Installer\EnumeratesInstalled;
use hkyss\Extras\Installer\Installer;
use hkyss\Extras\Installer\InstallerRegistry;
use hkyss\Extras\Installer\InstallPlan;
use hkyss\Extras\Installer\Intent;
use hkyss\Extras\Installer\Outcome;
use hkyss\Extras\Installer\StepKind;
use hkyss\Extras\Legacy\ElementType;
use hkyss\Extras\Takeover\SiteElement;
use hkyss\Extras\Takeover\Takeover;
use hkyss\Extras\Takeover\TakeoverPlan;
use hkyss\Extras\Takeover\TakeoverStep;
use hkyss\Extras\Tests\Support\FakeSiteElements;
use hkyss\Extras\Tests\Support\RememberedTakeovers;
use hkyss\Extras\Tests\Support\UnpackingInstaller;
use PHPUnit\Framework\TestCase;

class RecordingInstaller implements Installer, EnumeratesInstalled
{
    /** @var list<string> */
    public array $applied = [];
    public string $failOn = '';
    public string $blockOn = '';

    /** @var array<string, InstalledState> */
    private array $installed = [];

    public function supports(Extra $extra): bool
    {
        return true;
    }

    public function plan(Extra $extra, Intent $intent, string $version = ''): InstallPlan
    {
        $plan = new InstallPlan($extra->coordinate(), $extra->format(), $intent, $version);

        if ((string) $extra->coordinate() === $this->blockOn) {
            $plan->block('not a MODX Evolution Package');

            return $plan;
        }

        $plan->step(StepKind::ComposerRun, 'composer');

        return $plan;
    }

    public function apply(InstallPlan $plan): Outcome
    {
        $coordinate = (string) $plan->coordinate();

        if ($plan->intent() === Intent::Remove) {
            unset($this->installed[$coordinate]);
            $this->applied[] = 'remove ' . $coordinate;

            return Outcome::success($coordinate . ' removed');
        }

        if ($coordinate === $this->failOn) {
            return Outcome::failure('composer refused');
        }

        $this->installed[$coordinate] = InstalledState::present($plan->toVersion());
        $this->applied[] = 'install ' . $coordinate . ($plan->toVersion() !== '' ? '@' . $plan->toVersion() : '');

        return Outcome::success($coordinate . ' installed');
    }

    public function installedState(Extra $extra): InstalledState
    {
        return $this->installed[(string) $extra->coordinate()] ?? InstalledState::absent();
    }

    public function format(): ExtraFormat
    {
        return ExtraFormat::Composer;
    }

    /** @return array<string, InstalledState> */
    public function installed(): array
    {
        return $this->installed;
    }
}

class TakeoverTest extends TestCase
{
    public function testItSwitchesTheRowsOffInstallsAndRemembersBoth(): void
    {
        $editor = new SiteElement(ElementType::Plugin, 4, 'CodeMirror', '1.7');
        $site = new FakeSiteElements([$editor]);
        $records = new RememberedTakeovers();
        $installer = new RecordingInstaller();

        $outcome = $this->takeover($installer, $site, $records)->apply(new TakeoverPlan([
            TakeoverStep::replace($this->extra('evolution-cms/ecodemirror', ExtraFormat::Composer), [$editor]),
        ]));

        self::assertTrue($outcome->isSuccessful());
        self::assertSame(['plugins/codemirror'], $site->switchedOff);
        self::assertSame(['install evolution-cms/ecodemirror'], $installer->applied);
        self::assertCount(1, $records->written);
        self::assertSame('evolution-cms/ecodemirror', $records->written[0]->coordinate);
    }

    public function testAnAdoptedExtraSetsTheRowAsideAndInstallsBesideIt(): void
    {
        $snippet = new SiteElement(ElementType::Snippet, 2, 'Ditto', '2.1');
        $site = new FakeSiteElements([$snippet]);
        $records = new RememberedTakeovers();
        $installer = new RecordingInstaller();

        $this->takeover($installer, $site, $records)->apply(new TakeoverPlan([
            TakeoverStep::adopt($this->extra('extras-evolution/Ditto', ExtraFormat::Legacy), [$snippet], '2.1'),
        ]));

        self::assertSame(['snippets/ditto'], $site->switchedOff);
        self::assertSame(['Ditto.old'], $site->aside, 'out from under the name the installer looks for');
        self::assertSame(['install extras-evolution/Ditto@2.1'], $installer->applied);
        self::assertCount(1, $records->written[0]->elementList());
    }

    public function testAnAdoptionThatCannotSetARowAsideChangesNothing(): void
    {
        $snippet = new SiteElement(ElementType::Snippet, 2, 'Ditto', '2.1');
        $site = new FakeSiteElements([$snippet], [], [2]);
        $records = new RememberedTakeovers();
        $installer = new RecordingInstaller();

        $outcome = $this->takeover($installer, $site, $records)->apply(new TakeoverPlan([
            TakeoverStep::adopt($this->extra('extras-evolution/Ditto', ExtraFormat::Legacy), [$snippet], '2.1'),
        ]));

        self::assertTrue($outcome->isSuccessful());
        self::assertStringContainsString('1 left as it was', $outcome->message());
        self::assertSame([], $installer->applied, 'the installer would have written over the row');
        self::assertSame([], $records->written);
    }

    public function testARestoreGivesARowItsOwnNameBack(): void
    {
        $snippet = new SiteElement(ElementType::Snippet, 2, 'Ditto', '2.1');
        $site = new FakeSiteElements([$snippet]);
        $records = new RememberedTakeovers();
        $takeover = $this->takeover(new RecordingInstaller(), $site, $records);

        $takeover->apply(new TakeoverPlan([
            TakeoverStep::adopt($this->extra('extras-evolution/Ditto', ExtraFormat::Legacy), [$snippet], '2.1'),
        ]));
        $takeover->restore();

        self::assertSame(['snippets/2'], $site->switchedOn);
        self::assertSame(['Ditto'], $site->named);
        self::assertSame([], $records->written);
    }

    public function testTheLegacyManagerIsRememberedWithoutACoordinate(): void
    {
        $manager = new SiteElement(ElementType::Module, 1, 'Extras');
        $site = new FakeSiteElements([], [$manager]);
        $records = new RememberedTakeovers();
        $installer = new RecordingInstaller();

        $this->takeover($installer, $site, $records)->apply(new TakeoverPlan([
            TakeoverStep::retire($manager, 'this package is the manager now'),
        ]));

        self::assertSame(['modules/extras'], $site->switchedOff);
        self::assertSame([], $installer->applied);
        self::assertNull($records->written[0]->installed());
    }

    public function testOneInstallThatFailsPutsTheWholeTakeoverBack(): void
    {
        $editor = new SiteElement(ElementType::Plugin, 4, 'CodeMirror', '1.7');
        $manager = new SiteElement(ElementType::Module, 1, 'Extras');
        $site = new FakeSiteElements([$editor], [$manager]);
        $records = new RememberedTakeovers();
        $installer = new RecordingInstaller();
        $installer->failOn = 'evolution-cms/ecodemirror';

        $outcome = $this->takeover($installer, $site, $records)->apply(new TakeoverPlan([
            TakeoverStep::retire($manager, 'this package is the manager now'),
            TakeoverStep::replace($this->extra('evolution-cms/ecodemirror', ExtraFormat::Composer), [$editor]),
        ]));

        self::assertFalse($outcome->isSuccessful());
        self::assertSame(['plugins/4', 'modules/1'], $site->switchedOn, 'undone in the order it was done, backwards');
        self::assertSame([], $records->written, 'nothing is left in the ledger to undo twice');
    }

    public function testAnExtraWithNothingToInstallIsLeftAsItIsAndTheRestCarriesOn(): void
    {
        $snippet = new SiteElement(ElementType::Snippet, 2, 'Ditto', '2.1');
        $editor = new SiteElement(ElementType::Plugin, 4, 'CodeMirror', '1.7');
        $site = new FakeSiteElements([$snippet, $editor]);
        $records = new RememberedTakeovers();
        $installer = new RecordingInstaller();
        $installer->blockOn = 'extras-evolution/Ditto';

        $outcome = $this->takeover($installer, $site, $records)->apply(new TakeoverPlan([
            TakeoverStep::adopt($this->extra('extras-evolution/Ditto', ExtraFormat::Legacy), [$snippet]),
            TakeoverStep::replace($this->extra('evolution-cms/ecodemirror', ExtraFormat::Composer), [$editor]),
        ]));

        self::assertTrue($outcome->isSuccessful(), 'nothing was attempted for it, so nothing failed');
        self::assertStringContainsString('1 left as it was', $outcome->message());
        self::assertSame(['install evolution-cms/ecodemirror'], $installer->applied);
        self::assertCount(1, $records->written);
        self::assertSame('evolution-cms/ecodemirror', $records->written[0]->coordinate);
    }

    public function testARowSwitchedOffForAnExtraWithNothingToInstallComesBackOn(): void
    {
        $editor = new SiteElement(ElementType::Plugin, 4, 'CodeMirror', '1.7');
        $site = new FakeSiteElements([$editor]);
        $records = new RememberedTakeovers();
        $installer = new RecordingInstaller();
        $installer->blockOn = 'evolution-cms/ecodemirror';

        $outcome = $this->takeover($installer, $site, $records)->apply(new TakeoverPlan([
            TakeoverStep::replace($this->extra('evolution-cms/ecodemirror', ExtraFormat::Composer), [$editor]),
        ]));

        self::assertTrue($outcome->isSuccessful());
        self::assertSame(['plugins/codemirror'], $site->switchedOff);
        self::assertSame(['plugins/4'], $site->switchedOn);
        self::assertSame([], $records->written);
    }

    public function testRestoringRemovesWhatItInstalledAndSwitchesTheRowsBackOn(): void
    {
        $editor = new SiteElement(ElementType::Plugin, 4, 'CodeMirror', '1.7');
        $site = new FakeSiteElements([$editor]);
        $records = new RememberedTakeovers();
        $installer = new RecordingInstaller();
        $takeover = $this->takeover($installer, $site, $records);

        $takeover->apply(new TakeoverPlan([
            TakeoverStep::replace($this->extra('evolution-cms/ecodemirror', ExtraFormat::Composer), [$editor]),
        ]));

        $outcome = $takeover->restore();

        self::assertTrue($outcome->isSuccessful());
        self::assertSame(['install evolution-cms/ecodemirror', 'remove evolution-cms/ecodemirror'], $installer->applied);
        self::assertSame(['plugins/4'], $site->switchedOn);
        self::assertSame([], $records->written);
    }

    public function testRestoringWithNothingTakenOverChangesNothing(): void
    {
        $installer = new RecordingInstaller();

        $outcome = $this->takeover($installer, new FakeSiteElements(), new RememberedTakeovers())->restore();

        self::assertTrue($outcome->isSuccessful());
        self::assertSame([], $installer->applied);
    }

    public function testARowThatIsGoneIsReportedRatherThanRemembered(): void
    {
        $editor = new SiteElement(ElementType::Plugin, 4, 'CodeMirror', '1.7');
        $site = new FakeSiteElements([$editor], [], [4]);
        $records = new RememberedTakeovers();

        $outcome = $this->takeover(new RecordingInstaller(), $site, $records)->apply(new TakeoverPlan([
            TakeoverStep::replace($this->extra('evolution-cms/ecodemirror', ExtraFormat::Composer), [$editor]),
        ]));

        self::assertTrue($outcome->isSuccessful());
        self::assertSame(['not there any more: plugin CodeMirror'], $outcome->notes());
        self::assertSame([], $records->written[0]->elementList());
    }

    public function testItRefusesWhileTheRecordTableIsMissing(): void
    {
        $outcome = $this->takeover(
            new RecordingInstaller(),
            new FakeSiteElements(),
            new RememberedTakeovers(false)
        )->apply(new TakeoverPlan([
            TakeoverStep::retire(new SiteElement(ElementType::Module, 1, 'Extras'), 'replaced'),
        ]));

        self::assertFalse($outcome->isSuccessful());
        self::assertStringContainsString('php artisan migrate', $outcome->message());
    }

    public function testWhatPlanningUnpackedIsDiscarded(): void
    {
        $snippet = new SiteElement(ElementType::Snippet, 2, 'Ditto', '2.1');
        $installer = new UnpackingInstaller();

        $this->takeover($installer, new FakeSiteElements([$snippet]), new RememberedTakeovers())
            ->apply(new TakeoverPlan([
                TakeoverStep::adopt($this->extra('extras-evolution/Ditto', ExtraFormat::Legacy), [$snippet]),
            ]));

        self::assertSame(1, $installer->discarded);
        self::assertSame([], $installer->leftovers());

        $installer->remove();
    }

    private function takeover(
        Installer $installer,
        FakeSiteElements $site,
        RememberedTakeovers $records
    ): Takeover {
        return new Takeover(new InstallerRegistry([$installer]), $site, $records);
    }

    private function extra(string $coordinate, ExtraFormat $format): Extra
    {
        return new Extra(Coordinate::parse($coordinate), $format);
    }
}
