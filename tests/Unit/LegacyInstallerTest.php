<?php

namespace hkyss\Extras\Tests\Unit;

use hkyss\Extras\Domain\CompatibilityStatus;
use hkyss\Extras\Domain\Coordinate;
use hkyss\Extras\Domain\Extra;
use hkyss\Extras\Domain\ExtraFormat;
use hkyss\Extras\Exceptions\ExtrasException;
use hkyss\Extras\Installer\Intent;
use hkyss\Extras\Installer\LegacyInstaller;
use hkyss\Extras\Legacy\ElementWriter;
use hkyss\Extras\Record\InstallRecord;
use hkyss\Extras\Support\Paths;
use hkyss\Extras\Support\SiteCache;
use hkyss\Extras\Tests\Support\ArchiveHttp;
use hkyss\Extras\Tests\Support\MemoryRecords;
use hkyss\Extras\Tests\Support\SiteTables;
use hkyss\Extras\Tests\Support\TempTree;
use PHPUnit\Framework\TestCase;

class QuietCache extends SiteCache
{
    public function clear(): void
    {
    }
}

class LegacyInstallerTest extends TestCase
{
    private TempTree $site;
    private SiteTables $tables;
    private MemoryRecords $records;
    private ArchiveHttp $http;
    private LegacyInstaller $installer;
    private Extra $extra;

    protected function setUp(): void
    {
        $this->site = TempTree::make('extras-legacy-site');
        $this->site->directory('core');
        $this->records = new MemoryRecords();
        $this->tables = new SiteTables();
        $this->http = new ArchiveHttp([]);
        $this->installer = new LegacyInstaller(
            new Paths($this->site->path('core'), $this->site->path()),
            $this->http,
            $this->records,
            new ElementWriter($this->tables->connection()),
            cache: new QuietCache()
        );
        $this->extra = new Extra(
            Coordinate::parse('vendor/extra'),
            ExtraFormat::Legacy,
            compatibility: CompatibilityStatus::Verified
        );
    }

    protected function tearDown(): void
    {
        $this->installer->discardArchives();
        $this->site->remove();
    }

    public function testAFileTheSiteAlreadyHadIsLeftByTheRemoval(): void
    {
        $this->site->put('assets/lib/Shared.php', 'shared');
        $this->http->serve([
            'assets/lib/Shared.php' => 'shared',
            'assets/snippets/extra/extra.php' => 'extra',
        ]);

        $this->install('1.0');

        self::assertSame(['assets/snippets/extra/extra.php'], array_keys($this->record()->fileList()));

        $this->remove();

        self::assertFileExists($this->site->path('assets/lib/Shared.php'));
        self::assertFileDoesNotExist($this->site->path('assets/snippets/extra/extra.php'));
    }

    public function testAnUpdateKeepsAFileItBroughtThatDidNotChange(): void
    {
        $this->http->serve(['assets/snippets/extra/extra.php' => 'extra']);
        $this->install('1.0');

        $this->install('1.1');

        self::assertSame(['assets/snippets/extra/extra.php'], array_keys($this->record()->fileList()));
    }

    public function testAFileTheInstallReplacedIsBackedUpOutsideTheDocumentRoot(): void
    {
        $this->site->put('assets/lib/config.php', 'the site\'s own');
        $this->http->serve(['assets/lib/config.php' => 'the extra\'s']);

        $this->install('1.0');

        self::assertFileDoesNotExist($this->site->path('assets/lib/config.php.old'));
        self::assertSame(
            ['assets/lib/config.php' => $this->site->path('core/storage/extras/backups/vendor/extra/assets/lib/config.php')],
            $this->record()->backupMap()
        );

        $this->remove();

        self::assertSame('the site\'s own', file_get_contents($this->site->path('assets/lib/config.php')));
        self::assertDirectoryDoesNotExist($this->site->path('core/storage/extras/backups/vendor/extra'));
    }

    public function testTheSitesOwnCopySurvivesTwoUpdates(): void
    {
        $this->site->put('assets/lib/config.php', 'the site\'s own');
        $this->http->serve(['assets/lib/config.php' => 'one']);
        $this->install('1.0');

        $this->http->serve(['assets/lib/config.php' => 'two']);
        $this->install('1.1');
        $this->http->serve(['assets/lib/config.php' => 'three']);
        $this->install('1.2');

        self::assertSame('three', file_get_contents($this->site->path('assets/lib/config.php')));

        $this->remove();

        self::assertSame('the site\'s own', file_get_contents($this->site->path('assets/lib/config.php')));
    }

    public function testAnUpdateLeavesAFileYouEditedAndSaysSo(): void
    {
        $this->http->serve(['assets/snippets/extra/extra.php' => 'one']);
        $this->install('1.0');
        $this->site->put('assets/snippets/extra/extra.php', 'edited');

        $this->http->serve(['assets/snippets/extra/extra.php' => 'two']);
        $plan = $this->installer->plan($this->extra, Intent::Update, '1.1');
        $this->installer->apply($plan);

        self::assertSame('edited', file_get_contents($this->site->path('assets/snippets/extra/extra.php')));
        self::assertStringContainsString('assets/snippets/extra/extra.php', implode(' ', $plan->warnings()));
    }

    public function testAFileAnOlderVersionBroughtIsStillRemoved(): void
    {
        $this->http->serve(['assets/snippets/extra/old.php' => 'old', 'assets/snippets/extra/extra.php' => 'one']);
        $this->install('1.0');
        $this->http->serve(['assets/snippets/extra/extra.php' => 'two']);
        $this->install('1.1');

        $this->remove();

        self::assertFileDoesNotExist($this->site->path('assets/snippets/extra/old.php'));
    }

    public function testAStatementThatFailsStopsTheInstallAndWhatLandedCanBeRemoved(): void
    {
        $this->http->serve([
            'assets/snippets/extra/extra.php' => 'extra',
            'install/setup.data.sql' => "INSERT INTO nowhere VALUES (1);\nINSERT INTO categories (category) VALUES ('after');",
        ]);

        $outcome = $this->installer->apply($this->installer->plan($this->extra, Intent::Install, '1.0'));

        self::assertFalse($outcome->isSuccessful());
        self::assertStringContainsString('setup.data.sql', $outcome->message());
        self::assertSame(0, $this->tables->connection()->table('categories')->count(), 'nothing after the failed statement runs');

        $this->remove();

        self::assertFileDoesNotExist($this->site->path('assets/snippets/extra/extra.php'));
    }

    public function testAnUpdateRestoresTheRowAsTheSiteHadItBeforeTheFirstInstall(): void
    {
        $id = $this->tables->insert('site_snippets', ['name' => 'Extra', 'description' => 'the site\'s', 'snippet' => '// the site\'s']);
        $this->http->serve(['install/assets/snippets/Extra.tpl' => $this->snippet('1.0')]);
        $this->install('1.0');
        $this->http->serve(['install/assets/snippets/Extra.tpl' => $this->snippet('1.1')]);
        $this->install('1.1');

        self::assertStringContainsString('1.1', (string) $this->tables->row('site_snippets', $id)['snippet']);

        $this->remove();

        self::assertSame('// the site\'s', $this->tables->row('site_snippets', $id)['snippet']);
    }

    private function snippet(string $version): string
    {
        return "//<?php\n/**\n * Extra\n *\n * <strong>{$version}</strong> an extra\n *\n * @category snippet\n */\nreturn '{$version}';\n";
    }

    public function testARefThatIsNotAGitRefIsRefusedBeforeAnythingIsFetched(): void
    {
        $this->http->serve(['assets/snippets/extra/extra.php' => 'extra']);

        $this->expectException(ExtrasException::class);

        $this->installer->plan($this->extra, Intent::Install, '../../other/repo/archive/main');
    }

    private function install(string $version): void
    {
        $intent = $this->records->find($this->extra->coordinate()) === null ? Intent::Install : Intent::Update;
        $outcome = $this->installer->apply($this->installer->plan($this->extra, $intent, $version));

        self::assertTrue($outcome->isSuccessful(), $outcome->message());
    }

    private function remove(): void
    {
        $outcome = $this->installer->apply($this->installer->plan($this->extra, Intent::Remove));

        self::assertTrue($outcome->isSuccessful(), $outcome->message());
    }

    private function record(): InstallRecord
    {
        $record = $this->records->find($this->extra->coordinate());
        self::assertNotNull($record);

        return $record;
    }
}
