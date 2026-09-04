<?php

namespace hkyss\Extras\Record;

use hkyss\Extras\Takeover\SiteElement;
use hkyss\Extras\Takeover\TakeoverStep;
use Illuminate\Database\QueryException;

/** A missing table is a normal state, not a crash: the package installs before it migrates. */
class TakeoverRecordStore
{
    private ?bool $available = null;

    public function isAvailable(): bool
    {
        if ($this->available !== null) {
            return $this->available;
        }

        try {
            TakeoverRecord::query()->limit(1)->exists();
            $this->available = true;
        } catch (QueryException | \Throwable) {
            $this->available = false;
        }

        return $this->available;
    }

    /** @return list<TakeoverRecord> */
    public function all(): array
    {
        if (!$this->isAvailable()) {
            return [];
        }

        return TakeoverRecord::query()->orderByDesc('id')->get()->all();
    }

    /** @param list<SiteElement> $disabled */
    public function put(TakeoverStep $step, array $disabled): ?TakeoverRecord
    {
        if (!$this->isAvailable()) {
            return null;
        }

        $record = $this->record($step, $disabled);
        $record->save();

        return $record;
    }

    /** @param list<SiteElement> $disabled */
    protected function record(TakeoverStep $step, array $disabled): TakeoverRecord
    {
        return new TakeoverRecord([
            'coordinate' => (string) $step->coordinate(),
            'format' => $step->extra()?->format()->value ?? '',
            'action' => $step->action()->value,
            'elements' => array_map(static fn (SiteElement $element) => $element->toArray(), $disabled),
        ]);
    }

    public function forget(TakeoverRecord $record): bool
    {
        return (bool) $record->delete();
    }
}
