<?php

namespace hkyss\Extras\Installer;

use hkyss\Extras\Exceptions\ExtrasException;

/** Reads and edits core/custom/composer.json, which the core manifest merges. */
class ComposerManifest
{
    private string $path;

    public function __construct(string $path)
    {
        $this->path = $path;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function exists(): bool
    {
        return is_file($this->path);
    }

    /** @return array<string,mixed> */
    public function read(): array
    {
        if (!$this->exists()) {
            return $this->skeleton();
        }

        $decoded = json_decode((string) @file_get_contents($this->path), true);

        return is_array($decoded) ? $decoded : $this->skeleton();
    }

    /** @return array<string,string> */
    public function requirements(): array
    {
        $require = $this->read()['require'] ?? [];

        return is_array($require) ? $require : [];
    }

    public function constraintFor(string $coordinate): ?string
    {
        foreach ($this->requirements() as $name => $constraint) {
            if (strcasecmp((string) $name, $coordinate) === 0) {
                return (string) $constraint;
            }
        }

        return null;
    }

    public function require(string $coordinate, string $constraint): void
    {
        $data = $this->read();
        $data['require'] = is_array($data['require'] ?? null) ? $data['require'] : [];
        $data['require'][$coordinate] = $constraint;

        $this->write($data);
    }

    public function remove(string $coordinate): bool
    {
        $data = $this->read();
        $require = is_array($data['require'] ?? null) ? $data['require'] : [];
        $removed = false;

        foreach (array_keys($require) as $name) {
            if (strcasecmp((string) $name, $coordinate) === 0) {
                unset($require[$name]);
                $removed = true;
            }
        }

        if (!$removed) {
            return false;
        }

        $data['require'] = $require;
        $this->write($data);

        return true;
    }

    /** Null means the file did not exist, so a rollback deletes it rather than blanking it. */
    public function snapshot(): ?string
    {
        return $this->exists() ? (string) @file_get_contents($this->path) : null;
    }

    public function restore(?string $snapshot): void
    {
        if ($snapshot === null) {
            if (is_file($this->path)) {
                @unlink($this->path);
            }

            return;
        }

        @file_put_contents($this->path, $snapshot);
    }

    /** @param array<string,mixed> $data */
    private function write(array $data): void
    {
        $directory = dirname($this->path);

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new ExtrasException("Cannot create directory '{$directory}' for the custom manifest");
        }

        $encoded = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        if ($encoded === false || @file_put_contents($this->path, $encoded . "\n") === false) {
            throw new ExtrasException("Cannot write the custom manifest at '{$this->path}'");
        }
    }

    /** @return array<string,mixed> Matches what the core creates, so our file is indistinguishable. */
    private function skeleton(): array
    {
        return [
            'name' => 'evolutioncms/custom',
            'require' => [],
            'autoload' => ['psr-4' => []],
        ];
    }
}
