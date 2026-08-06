<?php

namespace hkyss\Extras\Support;

use hkyss\Extras\Exceptions\ExtrasException;

final class Paths
{
    private string $core;
    private ?string $documentRoot;

    public function __construct(?string $corePath = null, ?string $documentRoot = null)
    {
        $core = $corePath ?? (defined('EVO_CORE_PATH') ? (string) EVO_CORE_PATH : null);

        if ($core === null || $core === '') {
            throw new ExtrasException(
                'EVO_CORE_PATH is not defined; this package must run inside an Evolution CMS installation'
            );
        }

        $this->core = rtrim($core, '/\\') . DIRECTORY_SEPARATOR;
        $this->documentRoot = $documentRoot !== null
            ? rtrim($documentRoot, '/\\') . DIRECTORY_SEPARATOR
            : null;
    }

    public static function isAvailable(): bool
    {
        return defined('EVO_CORE_PATH') && (string) EVO_CORE_PATH !== '';
    }

    public function core(): string
    {
        return $this->core;
    }

    public function base(): string
    {
        if ($this->documentRoot !== null) {
            return $this->documentRoot;
        }

        if (defined('MODX_BASE_PATH') && (string) MODX_BASE_PATH !== '') {
            return rtrim((string) MODX_BASE_PATH, '/\\') . DIRECTORY_SEPARATOR;
        }

        return dirname(rtrim($this->core, '/\\')) . DIRECTORY_SEPARATOR;
    }

    /** User requirements go here; the core manifest merges this file and must not be touched. */
    public function customManifest(): string
    {
        return $this->core . 'custom' . DIRECTORY_SEPARATOR . 'composer.json';
    }

    public function providersDir(): string
    {
        return $this->core . 'custom' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR
            . 'app' . DIRECTORY_SEPARATOR . 'providers' . DIRECTORY_SEPARATOR;
    }

    public function vendorDir(): string
    {
        return $this->core . 'vendor' . DIRECTORY_SEPARATOR;
    }

    public function installedJson(): string
    {
        return $this->vendorDir() . 'composer' . DIRECTORY_SEPARATOR . 'installed.json';
    }

    public function composerHome(): string
    {
        return $this->core . 'composer';
    }

    public function artisan(): string
    {
        return $this->core . 'artisan';
    }

    public function cacheDir(): string
    {
        return $this->core . 'custom' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'extras';
    }

    public function packageDir(string $coordinate): string
    {
        return $this->vendorDir() . $coordinate . DIRECTORY_SEPARATOR;
    }

    /** Where package:discover copies assets; the admin can move it via the rb_base_dir setting. */
    public function publishBase(): string
    {
        if (function_exists('evo')) {
            $configured = @evo()->getConfig('rb_base_dir');

            if (is_string($configured) && $configured !== '') {
                return rtrim($configured, '/\\') . DIRECTORY_SEPARATOR;
            }
        }

        return $this->base();
    }

    public function ensureDirectory(string $path): string
    {
        if (!is_dir($path) && !@mkdir($path, 0775, true) && !is_dir($path)) {
            throw new ExtrasException("Cannot create directory '{$path}'");
        }

        return rtrim($path, '/\\') . DIRECTORY_SEPARATOR;
    }
}
