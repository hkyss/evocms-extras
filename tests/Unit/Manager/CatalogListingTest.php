<?php

namespace hkyss\Extras\Tests\Unit\Manager;

use hkyss\Extras\Catalog\Catalog;
use hkyss\Extras\Catalog\SnapshotSource;
use hkyss\Extras\Domain\Coordinate;
use hkyss\Extras\Domain\ExtraFormat;
use hkyss\Extras\Domain\InstalledState;
use hkyss\Extras\Installer\InstallerRegistry;
use hkyss\Extras\Manager\CatalogListing;
use hkyss\Extras\Manager\InstalledExtras;
use hkyss\Extras\Manager\ManagerModule;
use hkyss\Extras\Record\InstallRecordStore;
use hkyss\Extras\Support\Paths;
use hkyss\Extras\Tests\Support\FakeInstaller;
use hkyss\Extras\Tests\Support\TempTree;
use PHPUnit\Framework\TestCase;

class CatalogListingTest extends TestCase
{
    private TempTree $tree;

    protected function setUp(): void
    {
        $this->tree = TempTree::make('extras-listing');
        $this->tree->directory('core/vendor');
        $this->tree->put('catalog.json', (string) json_encode([
            'schema' => 1,
            'generated_at' => '2026-01-01T00:00:00Z',
            'extras' => [
                [
                    'coordinate' => 'acme/thing',
                    'format' => 'composer',
                    'title' => 'Thing',
                    'latest_release' => '2.0',
                    'versions' => ['2.0', '1.0'],
                    'compatibility' => 'verified',
                ],
                [
                    'coordinate' => ManagerModule::PACKAGE,
                    'format' => 'composer',
                    'title' => 'Extras Manager',
                    'compatibility' => 'verified',
                ],
            ],
        ]));
    }

    protected function tearDown(): void
    {
        $this->tree->remove();
    }

    /** @param array<string, array<string, InstalledState>> $installed */
    private function subject(array $installed): CatalogListing
    {
        $snapshot = new SnapshotSource('Bundled snapshot', $this->tree->path('catalog.json'));

        return new CatalogListing(
            new Catalog([$snapshot]),
            new InstalledExtras(
                new InstallerRegistry([
                    new FakeInstaller(ExtraFormat::Composer, $installed['composer'] ?? []),
                    new FakeInstaller(ExtraFormat::Legacy, $installed['legacy'] ?? []),
                ]),
                new InstallRecordStore(),
                $snapshot,
                new Paths($this->tree->path('core'))
            )
        );
    }

    public function testListsTheCatalogWithNothingInstalled(): void
    {
        $listing = $this->subject([])->all();

        self::assertSame(['acme/thing'], array_column($listing['extras'], 'coordinate'));
        self::assertFalse($listing['extras'][0]['installed']);
        self::assertSame(['2.0', '1.0'], $listing['extras'][0]['versions']);
    }

    public function testMarksTheOnesThatAreInstalled(): void
    {
        $listing = $this->subject(['composer' => ['acme/thing' => InstalledState::present('1.0', '^1.0')]])->all();

        self::assertTrue($listing['extras'][0]['installed']);
        self::assertSame('1.0', $listing['extras'][0]['version']);
        self::assertSame('^1.0', $listing['extras'][0]['constraint']);
    }

    public function testAnInstalledExtraTheCatalogDoesNotListStillShows(): void
    {
        $listing = $this->subject(['legacy' => ['old/plugin' => InstalledState::present('master')]])->all();

        self::assertSame(['acme/thing', 'old/plugin'], array_column($listing['extras'], 'coordinate'));

        $row = $listing['extras'][1];

        self::assertTrue($row['installed']);
        self::assertFalse($row['listed']);
        self::assertSame('legacy', $row['format']);
    }

    public function testTheModuleNeverOffersThePackageItIsPartOf(): void
    {
        $listing = $this->subject([
            'composer' => [ManagerModule::PACKAGE => InstalledState::present('1.1.0')],
        ])->all();

        self::assertNotContains(ManagerModule::PACKAGE, array_column($listing['extras'], 'coordinate'));
        self::assertNull($this->subject([])->installable(Coordinate::parse(ManagerModule::PACKAGE)));
    }

    public function testResolvesWhatTheCatalogCanInstall(): void
    {
        $extra = $this->subject([])->installable(Coordinate::parse('acme/thing'));

        self::assertNotNull($extra);
        self::assertSame(['2.0', '1.0'], $extra->versions());
        self::assertNull($this->subject([])->installable(Coordinate::parse('nobody/knows')));
    }

    public function testCarriesTheSourcesThatDidNotAnswer(): void
    {
        $listing = new CatalogListing(
            new Catalog([new SnapshotSource('Bundled snapshot', $this->tree->path('missing.json'))]),
            new InstalledExtras(new InstallerRegistry([]), new InstallRecordStore(), new SnapshotSource('x', $this->tree->path('missing.json')))
        );

        self::assertArrayHasKey('Bundled snapshot', $listing->all()['problems']);
    }
}
