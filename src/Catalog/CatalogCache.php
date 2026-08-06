<?php

namespace hkyss\Extras\Catalog;

use hkyss\Extras\Support\Paths;

class CatalogCache
{
    private string $directory;
    private int $ttl;
    private bool $enabled;

    public function __construct(Paths $paths, int $ttl = 3600, bool $enabled = true, ?string $directory = null)
    {
        $this->directory = rtrim($directory ?? $paths->cacheDir(), '/\\') . DIRECTORY_SEPARATOR;
        $this->ttl = $ttl;
        $this->enabled = $enabled;
    }

    /**
     * @template T
     * @param callable():T $producer
     * @return T
     */
    public function remember(string $key, callable $producer)
    {
        if (!$this->enabled) {
            return $producer();
        }

        $cached = $this->get($key);

        if ($cached !== null) {
            return $cached;
        }

        $value = $producer();
        $this->put($key, $value);

        return $value;
    }

    /** @return mixed|null */
    public function get(string $key)
    {
        if (!$this->enabled) {
            return null;
        }

        $file = $this->path($key);

        if (!is_file($file)) {
            return null;
        }

        $payload = json_decode((string) @file_get_contents($file), true);

        if (!is_array($payload) || !isset($payload['expires_at'], $payload['value'])) {
            return null;
        }

        if ((int) $payload['expires_at'] < time()) {
            @unlink($file);

            return null;
        }

        return $payload['value'];
    }

    /** @param mixed $value */
    public function put(string $key, $value): void
    {
        if (!$this->enabled) {
            return;
        }

        if (!is_dir($this->directory) && !@mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            return;
        }

        @file_put_contents($this->path($key), json_encode([
            'key' => $key,
            'expires_at' => time() + $this->ttl,
            'value' => $value,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public function forget(string $key): void
    {
        @unlink($this->path($key));
    }

    public function clear(): int
    {
        $removed = 0;

        foreach ($this->files() as $file) {
            if (@unlink($file)) {
                $removed++;
            }
        }

        return $removed;
    }

    /** @return array{entries:int,bytes:int,expired:int,directory:string,enabled:bool} */
    public function stats(): array
    {
        $entries = 0;
        $bytes = 0;
        $expired = 0;
        $now = time();

        foreach ($this->files() as $file) {
            $entries++;
            $bytes += (int) @filesize($file);

            $payload = json_decode((string) @file_get_contents($file), true);

            if (!is_array($payload) || (int) ($payload['expires_at'] ?? 0) < $now) {
                $expired++;
            }
        }

        return [
            'entries' => $entries,
            'bytes' => $bytes,
            'expired' => $expired,
            'directory' => $this->directory,
            'enabled' => $this->enabled,
        ];
    }

    /** @return list<string> */
    private function files(): array
    {
        return glob($this->directory . '*.json') ?: [];
    }

    private function path(string $key): string
    {
        return $this->directory . preg_replace('~[^a-z0-9_.-]+~i', '_', $key) . '.json';
    }
}
