<?php

namespace hkyss\Extras\Domain;

use hkyss\Extras\Exceptions\ExtrasException;

final class Coordinate
{
    private string $namespace;
    private string $name;

    private function __construct(string $namespace, string $name)
    {
        $this->namespace = $namespace;
        $this->name = $name;
    }

    public static function parse(string $value): self
    {
        $value = trim($value);

        if (!preg_match('~^([A-Za-z0-9][A-Za-z0-9._-]*)/([A-Za-z0-9][A-Za-z0-9._-]*)$~', $value, $m)) {
            throw new ExtrasException("Invalid coordinate '{$value}'; expected vendor/package or org/repo");
        }

        return new self($m[1], $m[2]);
    }

    public static function tryParse(string $value): ?self
    {
        try {
            return self::parse($value);
        } catch (ExtrasException) {
            return null;
        }
    }

    public function namespace(): string
    {
        return $this->namespace;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function __toString(): string
    {
        return $this->namespace . '/' . $this->name;
    }

    /** Case matters to GitHub but not to Packagist, so lookups go through here. */
    public function key(): string
    {
        return strtolower((string) $this);
    }

    public function equals(self $other): bool
    {
        return $this->key() === $other->key();
    }
}
