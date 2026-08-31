<?php

namespace hkyss\Extras\Takeover;

enum TakeoverAction: string
{
    case Retire = 'retire';
    case Replace = 'replace';
    case Adopt = 'adopt';
    case Skip = 'skip';

    /** Whether the rows the site has go dark. Everything a takeover acts on does. */
    public function disables(): bool
    {
        return $this !== self::Skip;
    }

    /**
     * Whether they go dark under another name as well. An installer of the legacy format finds
     * its elements by name, so a row left under its own would be written over rather than
     * replaced, and there would be nothing to switch back on.
     */
    public function setsAside(): bool
    {
        return $this === self::Adopt;
    }

    public function installs(): bool
    {
        return $this === self::Replace || $this === self::Adopt;
    }

    public function explain(): string
    {
        return match ($this) {
            self::Retire => 'switched off and not replaced: this package is what replaces it',
            self::Replace => 'switched off, and the package the catalog answers with put in its place',
            self::Adopt => 'set aside, and the same extra installed again through this tool',
            self::Skip => 'left as it is',
        };
    }
}
