<?php

namespace hkyss\Extras\Catalog;

use hkyss\Extras\Domain\Coordinate;
use hkyss\Extras\Domain\Extra;

interface CatalogSource
{
    public function name(): string;

    /** @return list<Extra> */
    public function all(): array;

    public function find(Coordinate $coordinate): ?Extra;

    /** Why this source returned nothing, when that was not the honest answer. */
    public function unavailableReason(): ?string;
}
