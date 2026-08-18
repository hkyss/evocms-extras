<?php

namespace hkyss\Extras\Domain;

enum CompatibilityStatus: string
{
    case Verified = 'verified';
    case Unknown = 'unknown';
    case Incompatible = 'incompatible';

    public static function fromNullable(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::Unknown;
    }

    public static function forFormat(ExtraFormat $format): self
    {
        return $format === ExtraFormat::Composer ? self::Verified : self::Unknown;
    }

    public function label(): string
    {
        return $this->value;
    }

    public function level(): string
    {
        return match ($this) {
            self::Verified => 'ok',
            self::Unknown => 'warn',
            self::Incompatible => 'fail',
        };
    }

    public function tag(): string
    {
        return match ($this) {
            self::Verified => '<fg=green>verified</>',
            self::Unknown => '<fg=yellow>unknown</>',
            self::Incompatible => '<fg=red>incompatible</>',
        };
    }

    public function explain(): string
    {
        return match ($this) {
            self::Verified => 'Verified against Evolution CMS 3.',
            self::Unknown => 'Never checked against Evolution CMS 3. Most legacy extras were written for MODX Evolution 1.x.',
            self::Incompatible => 'Known not to work on Evolution CMS 3.',
        };
    }
}
