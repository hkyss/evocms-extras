<?php

namespace hkyss\Extras\Catalog;

use hkyss\Extras\Domain\Coordinate;
use hkyss\Extras\Domain\Extra;
use hkyss\Extras\Domain\ExtraFormat;

class Catalog
{
    /** @var list<CatalogSource> */
    private array $sources;
    /** @var list<Extra>|null */
    private ?array $merged = null;

    /** @param list<CatalogSource> $sources */
    public function __construct(array $sources = [])
    {
        $this->sources = $sources;
    }

    public function addSource(CatalogSource $source): void
    {
        $this->sources[] = $source;
        $this->merged = null;
    }

    /** @return list<CatalogSource> */
    public function sources(): array
    {
        return $this->sources;
    }

    /** @return list<Extra> */
    public function all(): array
    {
        if ($this->merged !== null) {
            return $this->merged;
        }

        $byKey = [];

        foreach ($this->sources as $source) {
            foreach ($source->all() as $extra) {
                $key = $extra->coordinate()->key();

                if (!isset($byKey[$key])) {
                    $byKey[$key] = $extra;
                }
            }
        }

        $merged = array_values($byKey);
        usort($merged, static fn (Extra $a, Extra $b) => strcmp($a->coordinate()->key(), $b->coordinate()->key()));

        $this->merged = $merged;

        return $merged;
    }

    /** Falls back to per-source lookup so a coordinate missing from the snapshot still resolves. */
    public function find(Coordinate $coordinate): ?Extra
    {
        foreach ($this->all() as $extra) {
            if ($extra->coordinate()->equals($coordinate)) {
                return $extra;
            }
        }

        foreach ($this->sources as $source) {
            $found = $source->find($coordinate);

            if ($found !== null) {
                return $found->fromSource($source->name());
            }
        }

        return null;
    }

    /** @return list<Extra> */
    public function search(string $needle, ?ExtraFormat $format = null): array
    {
        $results = [];

        foreach ($this->all() as $extra) {
            if ($format !== null && $extra->format() !== $format) {
                continue;
            }

            if ($extra->matches($needle)) {
                $results[] = $extra;
            }
        }

        return $results;
    }

    /** @return array<string,string> */
    public function problems(): array
    {
        $problems = [];

        foreach ($this->sources as $source) {
            $reason = $source->unavailableReason();

            if ($reason !== null && $reason !== '') {
                $problems[$source->name()] = $reason;
            }
        }

        return $problems;
    }
}
