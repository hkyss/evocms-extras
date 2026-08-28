<?php

namespace hkyss\Extras\Tests\Unit\Manager;

use hkyss\Extras\Catalog\SnapshotSource;
use hkyss\Extras\Domain\Coordinate;
use hkyss\Extras\Domain\Extra;
use hkyss\Extras\Domain\ExtraFormat;
use hkyss\Extras\Domain\InstalledState;
use hkyss\Extras\Installer\InstallerRegistry;
use hkyss\Extras\Manager\InstalledExtras;
use hkyss\Extras\Manager\ManagerModule;
use hkyss\Extras\Record\InstallRecordStore;
use hkyss\Extras\Support\Paths;
use hkyss\Extras\Tests\Support\FakeInstaller;
use hkyss\Extras\Tests\Support\TempTree;
use PHPUnit\Framework\TestCase;

class InstalledExtrasTest extends TestCase
{
    private TempTree $tree;

    protected function setUp(): void
    {
        $this->tree = TempTree::make('extras-installed');
        $this->tree->directory('core/vendor/acme/listed');
        $this->tree->put('catalog.json', (string) json_encode([
            'schema' => 1,
            'generated_at' => '2026-01-01T00:00:00Z',
            'extras' => [[
                'coordinate' => 'acme/listed',
                'format' => 'composer',
                'title' => 'Listed',
                'description' => 'from the snapshot',
                'compatibility' => 'verified',
            ]],
        ]));
    }

    protected function tearDown(): void
    {
        $this->tree->remove();
    }

    /** @param array<string, array<string, InstalledState>> $installed */
    private function subject(array $installed): InstalledExtras
    {
        return new InstalledExtras(
            new InstallerRegistry([
                new FakeInstaller(ExtraFormat::Composer, $installed['composer'] ?? []),
                new FakeInstaller(ExtraFormat::Legacy, $installed['legacy'] ?? []),
            ]),
            new InstallRecordStore(),
            new SnapshotSource('Bundled snapshot', $this->tree->path('catalog.json')),
            new Paths($this->tree->path('core'))
        );
    }

    public function testKeysWhatIsInstalledByLowercaseCoordinate(): void
    {
        $map = $this->subject(['composer' => ['Acme/Listed' => InstalledState::present('1.2.3', '^1.0')]])->map();

        self::assertSame(['acme/listed'], array_keys($map));
        self::assertSame('1.2.3', $map['acme/listed']['state']->version());
    }

    public function testListsWhatIsInstalledAndNothingElse(): void
    {
        $rows = $this->subject([
            'composer' => [
                'acme/listed' => InstalledState::present('1.2.3', '^1.0'),
                ManagerModule::PACKAGE => InstalledState::present('1.1.0'),
            ],
        ])->all();

        self::assertSame(['acme/listed'], array_column($rows, 'coordinate'));
        self::assertTrue($rows[0]['installed']);
        self::assertSame('1.2.3', $rows[0]['version']);
        self::assertSame('Listed', $rows[0]['title']);
        self::assertTrue($rows[0]['listed']);
    }

    public function testAnInstalledExtraTheSnapshotDoesNotCarryIsMarked(): void
    {
        $rows = $this->subject(['legacy' => ['old/plugin' => InstalledState::present('master')]])->all();

        self::assertSame('old/plugin', $rows[0]['coordinate']);
        self::assertFalse($rows[0]['listed']);
        self::assertSame('unknown', $rows[0]['compatibility']);
    }

    public function testTheFormatOnDiskWinsOverTheOneTheSnapshotClaims(): void
    {
        $extra = $this->subject(['legacy' => ['acme/listed' => InstalledState::present('master')]])
            ->extraFor(Coordinate::parse('acme/listed'));

        self::assertNotNull($extra);
        self::assertSame(ExtraFormat::Legacy, $extra->format());
        self::assertSame('Listed', $extra->title());
    }

    public function testNothingIsResolvedForAPackageThatIsNotInstalled(): void
    {
        self::assertNull($this->subject([])->extraFor(Coordinate::parse('acme/listed')));
    }

    public function testDescribesAnExtraNobodyInstalled(): void
    {
        $row = $this->subject([])->describe($this->extra(), InstalledState::absent(), true);

        self::assertFalse($row['installed']);
        self::assertSame('', $row['version']);
        self::assertSame('2.0', $row['latest']);
        self::assertSame('from the catalog', $row['description']);
        self::assertTrue($row['listed']);
    }

    public function testTheInstalledPackageDescribesItselfOverTheCatalog(): void
    {
        $this->tree->put('core/vendor/acme/listed/composer.json', (string) json_encode([
            'description' => 'what the installed copy says',
            'type' => 'evolutioncms-plugin',
        ]));

        $row = $this->subject([])->describe($this->extra(), InstalledState::present('1.2.3', '^1.0'), true);

        self::assertSame('what the installed copy says', $row['description']);
        self::assertSame('evolutioncms-plugin', $row['type']);
        self::assertTrue($row['installed']);
        self::assertSame('^1.0', $row['constraint']);
    }

    public function testAnExtraTheCatalogNeverHeardOfIsMarkedAsSuch(): void
    {
        $row = $this->subject([])->describe($this->extra(), InstalledState::present('2.0'), false);

        self::assertFalse($row['listed']);
    }

    private function extra(): Extra
    {
        return Extra::fromArray([
            'coordinate' => 'acme/listed',
            'format' => 'composer',
            'title' => 'Listed',
            'description' => 'from the catalog',
            'latest_release' => '2.0',
            'versions' => ['2.0', '1.0'],
            'compatibility' => 'verified',
        ]);
    }
}
