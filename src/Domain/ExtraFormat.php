<?php

namespace hkyss\Extras\Domain;

enum ExtraFormat: string
{
    case Composer = 'composer';
    case Legacy = 'legacy';

    public function label(): string
    {
        return match ($this) {
            self::Composer => 'composer',
            self::Legacy => 'legacy',
        };
    }

    /** Legacy extras leave no manifest behind, so removal needs an install record. */
    public function isSelfDescribing(): bool
    {
        return $this === self::Composer;
    }
}
