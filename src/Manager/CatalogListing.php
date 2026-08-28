<?php

namespace hkyss\Extras\Manager;

use hkyss\Extras\Catalog\Catalog;
use hkyss\Extras\Domain\Coordinate;
use hkyss\Extras\Domain\Extra;
use hkyss\Extras\Domain\InstalledState;

/** The catalog and the site laid over each other: what can be installed, and what already is. */
class CatalogListing
{
    private Catalog $catalog;
    private InstalledExtras $installed;

    public function __construct(Catalog $catalog, InstalledExtras $installed)
    {
        $this->catalog = $catalog;
        $this->installed = $installed;
    }

    /** @return array{extras:list<array<string,mixed>>,problems:array<string,string>} */
    public function all(): array
    {
        $installed = $this->installed->map();
        $rows = [];

        foreach ($this->catalog->all() as $extra) {
            $key = $extra->coordinate()->key();

            if (isset($rows[$key]) || ManagerModule::isSelf($extra->coordinate())) {
                continue;
            }

            $state = $installed[$key]['state'] ?? InstalledState::absent();
            $rows[$key] = $this->installed->describe($extra, $state, true);
        }

        foreach ($installed as $key => $entry) {
            if (isset($rows[$key])) {
                continue;
            }

            $extra = $this->onlyHere((string) $key);

            if ($extra !== null) {
                $rows[$key] = $this->installed->describe($extra, $entry['state'], false);
            }
        }

        ksort($rows);

        return ['extras' => array_values($rows), 'problems' => $this->catalog->problems()];
    }

    public function installable(Coordinate $coordinate): ?Extra
    {
        return ManagerModule::isSelf($coordinate) ? null : $this->catalog->find($coordinate);
    }

    private function onlyHere(string $coordinate): ?Extra
    {
        $parsed = Coordinate::tryParse($coordinate);

        if ($parsed === null || ManagerModule::isSelf($parsed)) {
            return null;
        }

        return $this->installed->extraFor($parsed);
    }
}
