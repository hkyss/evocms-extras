<?php

namespace hkyss\Extras\Record;

use hkyss\Extras\Domain\Coordinate;
use hkyss\Extras\Domain\InstalledState;
use Illuminate\Database\QueryException;

/** A missing table is a normal state, not a crash: the package installs before it migrates. */
class InstallRecordStore
{
    private ?bool $available = null;

    public function isAvailable(): bool
    {
        if ($this->available !== null) {
            return $this->available;
        }

        try {
            InstallRecord::query()->limit(1)->exists();
            $this->available = true;
        } catch (QueryException | \Throwable) {
            $this->available = false;
        }

        return $this->available;
    }

    public function find(Coordinate $coordinate): ?InstallRecord
    {
        if (!$this->isAvailable()) {
            return null;
        }

        return InstallRecord::query()
            ->whereRaw('LOWER(coordinate) = ?', [$coordinate->key()])
            ->first();
    }

    /** @return list<InstallRecord> */
    public function all(): array
    {
        if (!$this->isAvailable()) {
            return [];
        }

        return InstallRecord::query()->orderBy('coordinate')->get()->all();
    }

    public function stateOf(Coordinate $coordinate): InstalledState
    {
        $record = $this->find($coordinate);

        return $record === null
            ? InstalledState::absent()
            : InstalledState::present((string) $record->version);
    }

    /** @param array<string,mixed> $attributes */
    public function put(Coordinate $coordinate, string $format, array $attributes): ?InstallRecord
    {
        if (!$this->isAvailable()) {
            return null;
        }

        $record = $this->find($coordinate) ?? new InstallRecord(['coordinate' => (string) $coordinate]);

        $record->fill($attributes + [
            'coordinate' => (string) $coordinate,
            'format' => $format,
        ]);

        $record->save();

        return $record;
    }

    public function forget(Coordinate $coordinate): bool
    {
        $record = $this->find($coordinate);

        return $record !== null && (bool) $record->delete();
    }
}
