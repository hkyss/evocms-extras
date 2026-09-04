<?php

namespace hkyss\Extras\Installer;

use hkyss\Extras\Domain\ExtraFormat;
use hkyss\Extras\Domain\InstalledState;

interface EnumeratesInstalled
{
    public function format(): ExtraFormat;

    /**
     * @return array<string, InstalledState>
     */
    public function installed(): array;
}
