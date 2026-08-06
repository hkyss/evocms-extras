<?php

namespace hkyss\Extras\Legacy;

use hkyss\Extras\Domain\Coordinate;
use hkyss\Extras\Exceptions\InstallationException;
use hkyss\Extras\Support\Http;
use hkyss\Extras\Support\PublishedAssets;

/**
 * A downloaded and unpacked legacy extra: assets/ goes to the site, install/assets holds
 * element descriptors, install/setup.data.sql is optional.
 */
class LegacyArchive
{
    private string $root;
    private string $tempDir;
    private DocblockParser $parser;

    private function __construct(string $root, string $tempDir, DocblockParser $parser)
    {
        $this->root = rtrim($root, '/\\');
        $this->tempDir = $tempDir;
        $this->parser = $parser;
    }

    public static function fetch(
        Coordinate $coordinate,
        string $ref,
        Http $http,
        ?DocblockParser $parser = null,
        ?string $tempRoot = null
    ): self {
        $tempDir = self::makeTempDir($tempRoot);
        $zip = $tempDir . DIRECTORY_SEPARATOR . 'archive.zip';
        $downloaded = false;

        foreach (["refs/tags/{$ref}", "refs/heads/{$ref}", $ref] as $candidate) {
            if ($http->download(sprintf('https://github.com/%s/archive/%s.zip', $coordinate, $candidate), $zip, true)) {
                $downloaded = true;
                break;
            }
        }

        if (!$downloaded) {
            self::removeTree($tempDir);

            throw InstallationException::unreadableArchive((string) $coordinate, "no archive found for ref '{$ref}'");
        }

        return new self(self::extract($zip, $tempDir, (string) $coordinate), $tempDir, $parser ?? new DocblockParser());
    }

    public static function opened(string $root, ?DocblockParser $parser = null): self
    {
        return new self($root, '', $parser ?? new DocblockParser());
    }

    public function root(): string
    {
        return $this->root;
    }

    public function isUsable(): bool
    {
        return is_dir($this->root) && ($this->hasAssets() || $this->descriptors() !== []);
    }

    public function hasAssets(): bool
    {
        return is_dir($this->assetsDir());
    }

    public function assetsDir(): string
    {
        return $this->root . DIRECTORY_SEPARATOR . 'assets';
    }

    /** @return list<string> */
    public function assetFiles(): array
    {
        return PublishedAssets::walk($this->assetsDir());
    }

    /** @return list<ElementDescriptor> */
    public function descriptors(): array
    {
        $base = $this->root . DIRECTORY_SEPARATOR . 'install' . DIRECTORY_SEPARATOR . 'assets';

        if (!is_dir($base)) {
            return [];
        }

        $descriptors = [];

        foreach ((array) glob($base . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) as $directory) {
            $type = ElementType::fromDirectory(basename((string) $directory));

            if ($type === null) {
                continue;
            }

            foreach ((array) glob($directory . DIRECTORY_SEPARATOR . '*.tpl') as $file) {
                $contents = @file_get_contents((string) $file);

                if ($contents === false) {
                    continue;
                }

                $descriptors[] = $this->parser->parse($type, $contents, pathinfo((string) $file, PATHINFO_FILENAME));
            }
        }

        return $descriptors;
    }

    public function sqlScript(string $tablePrefix): ?SqlScript
    {
        $file = $this->root . DIRECTORY_SEPARATOR . 'install' . DIRECTORY_SEPARATOR . 'setup.data.sql';

        if (!is_file($file)) {
            return null;
        }

        $contents = @file_get_contents($file);

        if ($contents === false || trim($contents) === '') {
            return null;
        }

        return SqlScript::parse($contents, $tablePrefix);
    }

    public function discard(): void
    {
        if ($this->tempDir !== '') {
            self::removeTree($this->tempDir);
        }
    }

    /** GitHub wraps archive contents in a single `<repo>-<ref>` directory. */
    private static function extract(string $zipPath, string $tempDir, string $coordinate): string
    {
        $zip = new \ZipArchive();

        if ($zip->open($zipPath) !== true) {
            self::removeTree($tempDir);

            throw InstallationException::unreadableArchive($coordinate, 'the downloaded file is not a zip archive');
        }

        $target = $tempDir . DIRECTORY_SEPARATOR . 'src';
        @mkdir($target, 0775, true);

        if (!$zip->extractTo($target)) {
            $zip->close();
            self::removeTree($tempDir);

            throw InstallationException::unreadableArchive($coordinate, 'extraction failed');
        }

        $zip->close();
        @unlink($zipPath);

        $entries = array_values(array_filter((array) glob($target . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR)));

        return count($entries) === 1 ? (string) $entries[0] : $target;
    }

    private static function makeTempDir(?string $tempRoot): string
    {
        $base = $tempRoot ?? sys_get_temp_dir();
        $dir = rtrim($base, '/\\') . DIRECTORY_SEPARATOR . 'evocms-extras-' . bin2hex(random_bytes(6));

        if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new InstallationException("Cannot create a temporary directory at '{$dir}'");
        }

        return $dir;
    }

    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);

            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($path);
    }
}
