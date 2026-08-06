<?php

namespace hkyss\Extras\Legacy;

final class ElementDescriptor
{
    private ElementType $type;
    private string $name;
    private string $description;
    private string $code;
    /** @var array<string,string> */
    private array $tags;

    /** @param array<string,string> $tags */
    public function __construct(
        ElementType $type,
        string $name,
        string $description,
        string $code,
        array $tags = []
    ) {
        $this->type = $type;
        $this->name = $name;
        $this->description = $description;
        $this->code = $code;
        $this->tags = $tags;
    }

    public function type(): ElementType
    {
        return $this->type;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function code(): string
    {
        return $this->code;
    }

    public function tag(string $name, string $default = ''): string
    {
        return $this->tags[strtolower($name)] ?? $default;
    }

    /** @return array<string,string> */
    public function tags(): array
    {
        return $this->tags;
    }

    public function category(): string
    {
        return $this->tag('modx_category');
    }

    public function version(): string
    {
        return $this->tag('version');
    }

    public function properties(): string
    {
        return $this->tag('properties');
    }

    /** Authors mark user-editable elements with @overwrite false. */
    public function mayOverwrite(): bool
    {
        $value = strtolower(trim($this->tag('overwrite', 'true')));

        return !in_array($value, ['false', '0', 'no', 'off'], true);
    }

    public function isDisabled(): bool
    {
        return trim($this->tag('disabled')) === '1';
    }

    /** @return list<string> */
    public function events(): array
    {
        return $this->splitList($this->tag('events'));
    }

    /** @return list<string> An empty list means every install set. */
    public function installSets(): array
    {
        return array_map('strtolower', $this->splitList($this->tag('installset')));
    }

    public function belongsToInstallSet(string $set): bool
    {
        $sets = $this->installSets();

        return $sets === [] || in_array(strtolower($set), $sets, true);
    }

    /** @return list<string> Older element names this one supersedes. */
    public function legacyNames(): array
    {
        return $this->splitList($this->tag('legacy_names'));
    }

    public function fingerprint(): string
    {
        return hash('sha256', $this->code);
    }

    public function identity(): string
    {
        return $this->type->value . '/' . $this->name;
    }

    /** @return list<string> */
    private function splitList(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }

        $parts = preg_split('~\s*,\s*~', trim($raw)) ?: [];

        return array_values(array_filter(array_map('trim', $parts), static fn ($p) => $p !== ''));
    }
}
