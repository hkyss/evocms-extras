<?php

namespace hkyss\Extras\Tests\Unit;

use hkyss\Extras\Support\PublishedAssets;
use PHPUnit\Framework\TestCase;

class PublishedAssetsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/extras-assets-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/source/nested', 0775, true);
        mkdir($this->root . '/site/nested', 0775, true);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->root);
    }

    private function put(string $path, string $contents): void
    {
        file_put_contents($this->root . '/' . $path, $contents);
    }

    private function rmrf(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);

            return;
        }

        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        ) as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($path);
    }

    public function testClassifiesIdenticalModifiedAndAbsent(): void
    {
        $this->put('source/same.txt', 'identical');
        $this->put('site/same.txt', 'identical');
        $this->put('source/changed.txt', 'from package');
        $this->put('site/changed.txt', 'edited by the user');
        $this->put('source/nested/only-in-package.txt', 'x');

        $comparison = PublishedAssets::compare($this->root . '/source', $this->root . '/site');

        self::assertSame(['same.txt'], $comparison->identical());
        self::assertSame(['changed.txt'], $comparison->modified());
        self::assertSame(['nested/only-in-package.txt'], $comparison->absent());
        self::assertTrue($comparison->hasModified());
    }

    public function testIgnoresFilesTheUserAdded(): void
    {
        $this->put('source/a.txt', 'a');
        $this->put('site/a.txt', 'a');
        $this->put('site/my-config.php', '<?php return [];');

        $comparison = PublishedAssets::compare($this->root . '/source', $this->root . '/site');

        self::assertSame(['a.txt'], $comparison->identical());
        self::assertSame([], $comparison->modified());
    }

    public function testDetectsSameSizeDifferentContent(): void
    {
        $this->put('source/x.txt', 'aaaa');
        $this->put('site/x.txt', 'bbbb');

        self::assertSame(['x.txt'], PublishedAssets::compare($this->root . '/source', $this->root . '/site')->modified());
    }

    public function testMissingSourceDirectoryYieldsNothing(): void
    {
        $comparison = PublishedAssets::compare($this->root . '/nope', $this->root . '/site');

        self::assertSame([], $comparison->identical());
        self::assertSame([], $comparison->modified());
        self::assertSame([], $comparison->absent());
    }

    public function testWalkReturnsSortedRelativePaths(): void
    {
        $this->put('source/b.txt', '1');
        $this->put('source/a.txt', '1');
        $this->put('source/nested/c.txt', '1');

        self::assertSame(['a.txt', 'b.txt', 'nested/c.txt'], PublishedAssets::walk($this->root . '/source'));
    }
}
