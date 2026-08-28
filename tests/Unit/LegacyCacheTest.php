<?php

namespace hkyss\Extras\Tests\Unit;

use hkyss\Extras\Domain\Coordinate;
use hkyss\Extras\Domain\ExtraFormat;
use hkyss\Extras\Installer\InstallPlan;
use hkyss\Extras\Installer\Intent;
use hkyss\Extras\Installer\LegacyInstaller;
use hkyss\Extras\Installer\StepKind;
use hkyss\Extras\Legacy\ElementType;
use hkyss\Extras\Legacy\ElementWriter;
use hkyss\Extras\Record\InstallRecordStore;
use hkyss\Extras\Support\Http;
use hkyss\Extras\Support\Paths;
use hkyss\Extras\Support\SiteCache;
use hkyss\Extras\Tests\Support\TempTree;
use PHPUnit\Framework\TestCase;

class CountingCache extends SiteCache
{
    public int $cleared = 0;

    public function clear(): void
    {
        $this->cleared++;
    }
}

class SilentElements extends ElementWriter
{
    /** @var list<int> */
    public array $deleted = [];

    public function delete(ElementType $type, int $id): bool
    {
        $this->deleted[] = $id;

        return true;
    }
}

class SilentRecords extends InstallRecordStore
{
    public function forget(Coordinate $coordinate): bool
    {
        return true;
    }
}

class LegacyCacheTest extends TestCase
{
    private ?TempTree $tree = null;

    protected function tearDown(): void
    {
        $this->tree?->remove();
        $this->tree = null;
    }

    public function testARemovalWritesTheSiteCacheAgain(): void
    {
        $this->tree = $tree = TempTree::make('extras-legacy-cache');
        $tree->directory('core');
        $tree->put('assets/modules/demo/core/src/Demo.php', 'demo');

        $paths = new Paths($tree->path('core'), $tree->path());
        $cache = new CountingCache();
        $elements = new SilentElements();

        $installer = new LegacyInstaller(
            $paths,
            new Http(),
            new SilentRecords(),
            $elements,
            '',
            '.old',
            'base',
            ['unknown', 'incompatible'],
            $cache
        );

        $plan = new InstallPlan(
            Coordinate::parse('vendor/demo'),
            ExtraFormat::Legacy,
            Intent::Remove
        );
        $plan->step(StepKind::FileDelete, 'delete Demo.php', [
            'path' => $tree->path('assets/modules/demo/core/src/Demo.php'),
        ]);
        $plan->step(StepKind::ElementDelete, 'delete plugin Demo', [
            'type' => ElementType::Plugin->value,
            'id' => 7,
        ]);

        $outcome = $installer->apply($plan);

        $this->assertTrue($outcome->isSuccessful());
        $this->assertSame([7], $elements->deleted);
        $this->assertSame(1, $cache->cleared);
        $this->assertDirectoryDoesNotExist($tree->path('assets/modules/demo'));
    }
}
