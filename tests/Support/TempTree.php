<?php

namespace hkyss\Extras\Tests\Support;

/** A throwaway directory a test can lay files into. */
class TempTree
{
    private string $root;

    private function __construct(string $root)
    {
        $this->root = $root;
    }

    public static function make(string $prefix): self
    {
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $prefix . '-' . bin2hex(random_bytes(6));
        mkdir($root, 0775, true);

        return new self($root);
    }

    public function path(string $relative = ''): string
    {
        return $relative === '' ? $this->root : $this->root . DIRECTORY_SEPARATOR . $relative;
    }

    public function directory(string $relative): string
    {
        $path = $this->path($relative);

        if (!is_dir($path)) {
            mkdir($path, 0775, true);
        }

        return $path;
    }

    public function put(string $relative, string $contents): void
    {
        $path = $this->path($relative);
        $directory = dirname($path);

        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        file_put_contents($path, $contents);
    }

    public function remove(): void
    {
        if (!is_dir($this->root)) {
            return;
        }

        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        ) as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($this->root);
    }
}
