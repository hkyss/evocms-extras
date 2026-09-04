<?php

namespace hkyss\Extras\Takeover;

use hkyss\Extras\Legacy\ElementType;

/** A row the site already carries: what a takeover switches off and a restore switches back on. */
final class SiteElement
{
    private ElementType $type;
    private int $id;
    private string $name;
    private string $version;
    private bool $disabled;

    public function __construct(
        ElementType $type,
        int $id,
        string $name,
        string $version = '',
        bool $disabled = false
    ) {
        $this->type = $type;
        $this->id = $id;
        $this->name = $name;
        $this->version = $version;
        $this->disabled = $disabled;
    }

    /** The legacy package format puts the version in bold ahead of the description, and nothing else writes there. */
    public static function versionIn(string $description): string
    {
        return preg_match('~<strong>\s*v?([0-9][0-9A-Za-z.\-]*)\s*</strong>~i', $description, $match) === 1
            ? $match[1]
            : '';
    }

    /** @param array<string,mixed> $row */
    public static function fromArray(array $row): ?self
    {
        $type = ElementType::tryFrom((string) ($row['type'] ?? ''));
        $id = (int) ($row['id'] ?? 0);

        if ($type === null || $id <= 0) {
            return null;
        }

        return new self($type, $id, (string) ($row['name'] ?? ''), (string) ($row['version'] ?? ''));
    }

    public function type(): ElementType
    {
        return $this->type;
    }

    public function id(): int
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function version(): string
    {
        return $this->version;
    }

    public function isDisabled(): bool
    {
        return $this->disabled;
    }

    public function label(): string
    {
        return $this->type->singular() . ' ' . $this->name;
    }

    /** How an element is addressed everywhere a name has to be matched: the site ignores case. */
    public function key(): string
    {
        return $this->type->value . '/' . mb_strtolower($this->name);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'id' => $this->id,
            'name' => $this->name,
            'version' => $this->version,
        ];
    }
}
