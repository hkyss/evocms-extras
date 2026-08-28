<?php

namespace hkyss\Extras\Tests\Unit;

use hkyss\Extras\Support\Paths;
use hkyss\Extras\Tests\Support\TempTree;
use PHPUnit\Framework\TestCase;

class PathsTest extends TestCase
{
    private ?TempTree $tree = null;

    protected function tearDown(): void
    {
        $this->tree?->remove();
        $this->tree = null;
    }

    public function testItTakesTheDirectoriesADeletionEmptied(): void
    {
        [$paths, $tree] = $this->tree();
        $tree->directory('assets/modules/demo/core/src');
        $tree->put('assets/modules/other.php', 'another module');

        $removed = $paths->pruneEmptyDirectories(
            $tree->path('assets/modules/demo/core/src/Demo.php'),
            $paths->base()
        );

        $this->assertSame(3, $removed);
        $this->assertDirectoryDoesNotExist($tree->path('assets/modules/demo'));
        $this->assertDirectoryExists($tree->path('assets/modules'));
    }

    public function testItStopsAtADirectoryThatStillHoldsSomething(): void
    {
        [$paths, $tree] = $this->tree();
        $tree->put('assets/modules/demo/keep.txt', 'keep');
        $tree->directory('assets/modules/demo/core');

        $removed = $paths->pruneEmptyDirectories(
            $tree->path('assets/modules/demo/core/Demo.php'),
            $paths->base()
        );

        $this->assertSame(1, $removed);
        $this->assertDirectoryExists($tree->path('assets/modules/demo'));
    }

    public function testItNeverClimbsPastWhereItWasToldToStop(): void
    {
        [$paths, $tree] = $this->tree();
        $stop = $tree->directory('assets/modules');

        $removed = $paths->pruneEmptyDirectories($stop . DIRECTORY_SEPARATOR . 'gone.php', $stop);

        $this->assertSame(0, $removed);
        $this->assertDirectoryExists($stop);
    }

    /** @return array{0: Paths, 1: TempTree} */
    private function tree(): array
    {
        $this->tree = $tree = TempTree::make('extras-paths');
        $tree->directory('core');

        return [new Paths($tree->path('core'), $tree->path()), $tree];
    }
}
