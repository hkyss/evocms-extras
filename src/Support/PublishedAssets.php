<?php

namespace hkyss\Extras\Support;

class PublishedAssets
{
    /** @var list<string> */
    private array $identical = [];
    /** @var list<string> */
    private array $modified = [];
    /** @var list<string> */
    private array $absent = [];

    public static function compare(string $sourceDir, string $destinationDir): self
    {
        $result = new self();

        $sourceDir = rtrim($sourceDir, '/\\');
        $destinationDir = rtrim($destinationDir, '/\\');

        if (!is_dir($sourceDir)) {
            return $result;
        }

        foreach (self::walk($sourceDir) as $relative) {
            $source = $sourceDir . DIRECTORY_SEPARATOR . $relative;
            $target = $destinationDir . DIRECTORY_SEPARATOR . $relative;

            if (!is_file($target)) {
                $result->absent[] = $relative;
                continue;
            }

            if (self::sameContents($source, $target)) {
                $result->identical[] = $relative;
                continue;
            }

            $result->modified[] = $relative;
        }

        return $result;
    }

    /** @return list<string> */
    public function identical(): array
    {
        return $this->identical;
    }

    /** @return list<string> */
    public function modified(): array
    {
        return $this->modified;
    }

    /** @return list<string> */
    public function absent(): array
    {
        return $this->absent;
    }

    public function hasModified(): bool
    {
        return $this->modified !== [];
    }

    /** @return list<string> */
    public static function walk(string $directory): array
    {
        $directory = rtrim($directory, '/\\');

        if (!is_dir($directory)) {
            return [];
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        $files = [];

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($directory) + 1);

            if ($relative !== '') {
                $files[] = str_replace('\\', '/', $relative);
            }
        }

        sort($files);

        return $files;
    }

    private static function sameContents(string $a, string $b): bool
    {
        if (filesize($a) !== filesize($b)) {
            return false;
        }

        return hash_file('sha256', $a) === hash_file('sha256', $b);
    }
}
