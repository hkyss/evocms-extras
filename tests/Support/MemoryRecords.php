<?php

namespace hkyss\Extras\Tests\Support;

use hkyss\Extras\Domain\Coordinate;
use hkyss\Extras\Record\InstallRecord;
use hkyss\Extras\Record\InstallRecordStore;

/** Install records held in memory, so a test can read what an install wrote down. */
class MemoryRecords extends InstallRecordStore
{
    /** @var array<string,InstallRecord> */
    private array $records = [];

    public function isAvailable(): bool
    {
        return true;
    }

    public function find(Coordinate $coordinate): ?InstallRecord
    {
        return $this->records[$coordinate->key()] ?? null;
    }

    /** @return list<InstallRecord> */
    public function all(): array
    {
        return array_values($this->records);
    }

    /** @param array<string,mixed> $attributes */
    public function put(Coordinate $coordinate, string $format, array $attributes): ?InstallRecord
    {
        $record = $this->find($coordinate) ?? new InstallRecord(['coordinate' => (string) $coordinate]);
        $record->fill($attributes + ['coordinate' => (string) $coordinate, 'format' => $format]);

        return $this->records[$coordinate->key()] = $record;
    }

    public function forget(Coordinate $coordinate): bool
    {
        $known = isset($this->records[$coordinate->key()]);
        unset($this->records[$coordinate->key()]);

        return $known;
    }
}
