<?php

namespace hkyss\Extras\Tests\Unit;

use hkyss\Extras\Domain\CompatibilityStatus;
use hkyss\Extras\Domain\Coordinate;
use hkyss\Extras\Domain\Extra;
use hkyss\Extras\Domain\ExtraFormat;
use hkyss\Extras\Installer\Intent;
use hkyss\Extras\Installer\LegacyInstaller;
use hkyss\Extras\Legacy\ElementWriter;
use hkyss\Extras\Record\InstallRecord;
use hkyss\Extras\Support\Paths;
use hkyss\Extras\Support\SiteCache;
use hkyss\Extras\Tests\Support\ArchiveHttp;
use hkyss\Extras\Tests\Support\MemoryRecords;
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
    private MemoryRecords $records;
    private ArchiveHttp $http;
    private LegacyInstaller $installer;
    private Extra $extra;

    protected function setUp(): void
    {
        $this->site = TempTree::make('extras-legacy-site');
        $this->site->directory('core');
        $this->records = new MemoryRecords();
        $this->http = new ArchiveHttp([]);
        $this->installer = new LegacyInstaller(
            new Paths($this->site->path('core'), $this->site->path()),
            $this->http,
            $this->records,
            new ElementWriter(),
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
