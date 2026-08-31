<?php

namespace hkyss\Extras\Tests\Unit\Takeover;

use hkyss\Extras\Catalog\Catalog;
use hkyss\Extras\Catalog\CatalogSource;
use hkyss\Extras\Domain\Coordinate;
use hkyss\Extras\Domain\Extra;
use hkyss\Extras\Domain\ExtraFormat;
use hkyss\Extras\Domain\InstalledState;
use hkyss\Extras\Installer\InstallerRegistry;
use hkyss\Extras\Legacy\ElementType;
use hkyss\Extras\Record\InstallRecord;
use hkyss\Extras\Record\InstallRecordStore;
use hkyss\Extras\Takeover\SiteElement;
use hkyss\Extras\Takeover\TakeoverAction;
use hkyss\Extras\Takeover\TakeoverPlanner;
use hkyss\Extras\Tests\Support\FakeInstaller;
use hkyss\Extras\Tests\Support\FakeSiteElements;
use PHPUnit\Framework\TestCase;

class KnownRecords extends InstallRecordStore
{
    /** @var list<InstallRecord> */
    private array $records;

    /** @param list<InstallRecord> $records */
    public function __construct(array $records = [])
    {
        $this->records = $records;
    }

    public function isAvailable(): bool
    {
        return true;
    }

    /** @return list<InstallRecord> */
    public function all(): array
    {
        return $this->records;
    }
}

class TakeoverPlannerTest extends TestCase
{
    public function testTheLegacyManagerIsSwitchedOffAndNothingTakesItsPlace(): void
    {
        $manager = new SiteElement(ElementType::Module, 1, 'Extras');

        $plan = $this->planner(new FakeSiteElements([], [$manager]))->plan();

        $steps = $plan->of(TakeoverAction::Retire);

        self::assertCount(1, $steps);
        self::assertNull($steps[0]->coordinate());
        self::assertSame([$manager], $steps[0]->disabled());
        self::assertStringContainsString('switch off module Extras', $steps[0]->summary());
    }

    public function testAManagerAlreadySwitchedOffIsLeftAlone(): void
    {
        $manager = new SiteElement(ElementType::Module, 1, 'Extras', '', true);

        self::assertTrue($this->planner(new FakeSiteElements([], [$manager]))->plan()->isEmpty());
    }

    public function testARowTheCatalogAnswersForWithAPackageIsSwitchedOffAndReplaced(): void
    {
        $editor = new SiteElement(ElementType::Plugin, 4, 'CodeMirror', '1.7');

        $plan = $this->planner(
            new FakeSiteElements([$editor]),
            ['CodeMirror' => 'evolution-cms/ecodemirror']
        )->plan();

        $steps = $plan->of(TakeoverAction::Replace);

        self::assertCount(1, $steps);
        self::assertSame('evolution-cms/ecodemirror', (string) $steps[0]->coordinate());
        self::assertSame([$editor], $steps[0]->disabled());
    }

    public function testALegacyExtraIsAdoptedAtTheVersionTheSiteRuns(): void
    {
        $snippet = new SiteElement(ElementType::Snippet, 2, 'Ditto', '2.1');
        $plugin = new SiteElement(ElementType::Plugin, 3, 'Ditto', '2.1');

        $steps = $this->planner(new FakeSiteElements([$snippet, $plugin]))
            ->plan()
            ->of(TakeoverAction::Adopt);

        self::assertCount(1, $steps);
        self::assertSame('extras-evolution/Ditto', (string) $steps[0]->coordinate());
        self::assertSame('2.1', $steps[0]->version());
        self::assertSame([$plugin, $snippet], $steps[0]->elements(), 'plugins are read before snippets');
        self::assertSame([], $steps[0]->disabled(), 'an adopted extra is written over, not switched off');
    }

    public function testAVersionTheCatalogDoesNotPublishIsNotPinned(): void
    {
        $steps = $this->planner(new FakeSiteElements([new SiteElement(ElementType::Snippet, 2, 'Ditto', '1.0')]))
            ->plan()
            ->of(TakeoverAction::Adopt);

        self::assertSame('', $steps[0]->version());
    }

    public function testAnElementNoPackageWroteIsLeftAlone(): void
    {
        $steps = $this->planner(new FakeSiteElements([new SiteElement(ElementType::Snippet, 2, 'Ditto')]))
            ->plan()
            ->of(TakeoverAction::Skip);

        self::assertCount(1, $steps);
        self::assertStringContainsString('carries no version', $steps[0]->reason());
    }

    public function testAnElementTheCatalogCannotNameIsLeftAlone(): void
    {
        $steps = $this->planner(new FakeSiteElements([new SiteElement(ElementType::Plugin, 9, 'TransAlias', '1.0.4')]))
            ->plan()
            ->of(TakeoverAction::Skip);

        self::assertCount(1, $steps);
        self::assertStringContainsString('nothing in the catalog', $steps[0]->reason());
    }

    public function testAReplacementThisInstallationCouldNotInstallIsLeftOutOfThePlan(): void
    {
        $steps = $this->planner(new FakeSiteElements([new SiteElement(ElementType::Plugin, 8, 'efuture', '1.0')]))
            ->plan()
            ->of(TakeoverAction::Skip);

        self::assertCount(1, $steps);
        self::assertStringContainsString('needs PHP ^99.0', $steps[0]->reason());
    }

    public function testTheIgnoreListKeepsARowOutOfIt(): void
    {
        $steps = $this->planner(
            new FakeSiteElements([new SiteElement(ElementType::Snippet, 2, 'Ditto', '2.1')]),
            [],
            ['ditto']
        )->plan()->of(TakeoverAction::Skip);

        self::assertCount(1, $steps);
        self::assertStringContainsString('configuration', $steps[0]->reason());
    }

    public function testARowSomebodyHasAlreadySwitchedOffStaysThatWay(): void
    {
        $site = new FakeSiteElements([new SiteElement(ElementType::Snippet, 2, 'Ditto', '2.1', true)]);

        self::assertSame([], $this->planner($site)->plan()->steps());
    }

    public function testAnElementAnInstallRecordAlreadyOwnsIsNotTouched(): void
    {
        $records = new KnownRecords([new InstallRecord([
            'coordinate' => 'extras-evolution/Ditto',
            'elements' => [['type' => 'snippets', 'name' => 'Ditto', 'id' => 2]],
        ])]);

        $plan = $this->planner(
            new FakeSiteElements([new SiteElement(ElementType::Snippet, 2, 'Ditto', '2.1')]),
            [],
            [],
            $records
        )->plan();

        self::assertSame([], $plan->steps());
    }

    public function testAnExtraAlreadyInstalledIsSwitchedOffRatherThanInstalledAgain(): void
    {
        $steps = $this->planner(
            new FakeSiteElements([new SiteElement(ElementType::Snippet, 2, 'Ditto', '2.1')]),
            [],
            [],
            null,
            ['extras-evolution/Ditto' => InstalledState::present('2.1')]
        )->plan()->of(TakeoverAction::Retire);

        self::assertCount(1, $steps);
        self::assertStringContainsString('extras-evolution/Ditto is installed', $steps[0]->summary());
    }

    public function testNothingTheCatalogCannotNameIsEverSwitchedOff(): void
    {
        $site = new FakeSiteElements(
            [
                new SiteElement(ElementType::Plugin, 9, 'TransAlias', '1.0.4'),
                new SiteElement(ElementType::Plugin, 8, 'efuture', '1.0'),
                new SiteElement(ElementType::Snippet, 7, 'Nav'),
            ],
            [new SiteElement(ElementType::Module, 1, 'Extras')]
        );

        $off = [];

        foreach ($this->planner($site)->plan()->steps() as $step) {
            foreach ($step->disabled() as $element) {
                $off[] = $element->label();
            }
        }

        self::assertSame(['module Extras'], $off, 'only the manager this package replaces');
    }

    /**
     * @param array<string,string>          $replacements
     * @param list<string>                  $ignored
     * @param array<string, InstalledState> $installed
     */
    private function planner(
        FakeSiteElements $site,
        array $replacements = [],
        array $ignored = [],
        ?InstallRecordStore $records = null,
        array $installed = []
    ): TakeoverPlanner {
        return new TakeoverPlanner(
            new Catalog([$this->source()]),
            $site,
            $records ?? new KnownRecords(),
            new InstallerRegistry([
                new FakeInstaller(ExtraFormat::Legacy, $installed),
                new FakeInstaller(ExtraFormat::Composer, $installed),
            ]),
            $replacements,
            $ignored
        );
    }

    private function source(): CatalogSource
    {
        return new class () implements CatalogSource {
            /** @return list<Extra> */
            public function all(): array
            {
                return [
                    new Extra(
                        Coordinate::parse('extras-evolution/Ditto'),
                        ExtraFormat::Legacy,
                        'Ditto',
                        'Document lister',
                        '3.0',
                        ['3.0', '2.1'],
                        'master'
                    ),
                    new Extra(
                        Coordinate::parse('evolution-cms/ecodemirror'),
                        ExtraFormat::Composer,
                        'ecodemirror',
                        'CodeMirror 6'
                    ),
                    new Extra(
                        Coordinate::parse('evolution-cms/efuture'),
                        ExtraFormat::Composer,
                        'efuture',
                        'For a PHP nobody runs',
                        '',
                        [],
                        '',
                        null,
                        '',
                        '',
                        '',
                        '',
                        ['php' => '^99.0']
                    ),
                ];
            }

            public function find(Coordinate $coordinate): ?Extra
            {
                foreach ($this->all() as $extra) {
                    if ($extra->coordinate()->equals($coordinate)) {
                        return $extra;
                    }
                }

                return null;
            }

            public function name(): string
            {
                return 'test';
            }

            public function unavailableReason(): ?string
            {
                return null;
            }
        };
    }
}
