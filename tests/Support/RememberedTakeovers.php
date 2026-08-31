<?php

namespace hkyss\Extras\Tests\Support;

use hkyss\Extras\Record\TakeoverRecord;
use hkyss\Extras\Record\TakeoverRecordStore;
use hkyss\Extras\Takeover\TakeoverStep;

/** The ledger in memory: the rows a takeover writes, without a database under them. */
class RememberedTakeovers extends TakeoverRecordStore
{
    /** @var list<TakeoverRecord> */
    public array $written = [];

    private bool $available;

    public function __construct(bool $available = true)
    {
        $this->available = $available;
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    /** @return list<TakeoverRecord> */
    public function all(): array
    {
        return array_reverse($this->written);
    }

    /** @param list<\hkyss\Extras\Takeover\SiteElement> $disabled */
    public function put(TakeoverStep $step, array $disabled): ?TakeoverRecord
    {
        if (!$this->available) {
            return null;
        }

        $record = $this->record($step, $disabled);
        $this->written[] = $record;

        return $record;
    }

    public function forget(TakeoverRecord $record): bool
    {
        $this->written = array_values(array_filter(
            $this->written,
            static fn (TakeoverRecord $kept) => $kept !== $record
        ));

        return true;
    }
}
