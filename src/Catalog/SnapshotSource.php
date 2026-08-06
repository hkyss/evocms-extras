<?php

namespace hkyss\Extras\Catalog;

use hkyss\Extras\Domain\Coordinate;
use hkyss\Extras\Domain\Extra;

class SnapshotSource implements CatalogSource
{
    private string $name;
    private string $path;
    private ?string $unavailable = null;
    /** @var list<Extra>|null */
    private ?array $extras = null;

    public function __construct(string $name = 'Bundled snapshot', ?string $path = null)
    {
        $this->name = $name;
        $this->path = $path ?? self::bundledPath();
    }

    public static function bundledPath(): string
    {
        return dirname(__DIR__, 2) . '/resources/catalog.json';
    }

    public function name(): string
    {
        return $this->name;
    }

    public function all(): array
    {
        if ($this->extras !== null) {
            return $this->extras;
        }

        $this->extras = $this->load();

        return $this->extras;
    }

    public function find(Coordinate $coordinate): ?Extra
    {
        foreach ($this->all() as $extra) {
            if ($extra->coordinate()->equals($coordinate)) {
                return $extra;
            }
        }

        return null;
    }

    public function unavailableReason(): ?string
    {
        $this->all();

        return $this->unavailable;
    }

    /** @return list<Extra> */
    private function load(): array
    {
        if (!is_file($this->path)) {
            $this->unavailable = "Snapshot file not found at {$this->path}";

            return [];
        }

        $decoded = json_decode((string) @file_get_contents($this->path), true);

        if (!is_array($decoded) || !isset($decoded['extras']) || !is_array($decoded['extras'])) {
            $this->unavailable = "Snapshot at {$this->path} is not a valid catalog document";

            return [];
        }

        $extras = [];

        foreach ($decoded['extras'] as $row) {
            if (!is_array($row) || Coordinate::tryParse((string) ($row['coordinate'] ?? '')) === null) {
                continue;
            }

            $extras[] = Extra::fromArray($row)->fromSource($this->name);
        }

        return $extras;
    }

    /**
     * @param list<Extra> $extras
     * @return array<string,mixed>
     */
    public static function document(array $extras, string $generatedAt): array
    {
        $rows = [];

        foreach ($extras as $extra) {
            $row = $extra->toArray();
            unset($row['source']);
            $rows[] = $row;
        }

        usort($rows, static fn (array $a, array $b) => strcmp((string) $a['coordinate'], (string) $b['coordinate']));

        return [
            'schema' => 1,
            'generated_at' => $generatedAt,
            'extras' => $rows,
        ];
    }
}
