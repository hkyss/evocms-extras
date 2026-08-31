<?php

namespace hkyss\Extras\Takeover;

enum TakeoverAction: string
{
    case Retire = 'retire';
    case Replace = 'replace';
    case Adopt = 'adopt';
    case Skip = 'skip';

    /** Whether the site's own rows go dark; an adopted extra is written over, not switched off. */
    public function disables(): bool
    {
        return $this === self::Retire || $this === self::Replace;
    }

    public function installs(): bool
    {
        return $this === self::Replace || $this === self::Adopt;
    }

    public function explain(): string
    {
        return match ($this) {
            self::Retire => 'switched off and not replaced: this package is what replaces it',
            self::Replace => 'switched off and installed again from the catalog',
            self::Adopt => 'installed through this tool, over the rows already there',
            self::Skip => 'left as it is',
        };
    }
}
