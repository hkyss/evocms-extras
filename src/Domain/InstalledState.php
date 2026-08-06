<?php

namespace hkyss\Extras\Domain;

final class InstalledState
{
    private bool $installed;
    private string $version;
    private string $constraint;

    private function __construct(bool $installed, string $version, string $constraint)
    {
        $this->installed = $installed;
        $this->version = $version;
        $this->constraint = $constraint;
    }

    public static function absent(): self
    {
        return new self(false, '', '');
    }

    /** For composer extras the constraint (`^1.0`) differs from the resolved version (`1.2.3`). */
    public static function present(string $version, string $constraint = ''): self
    {
        return new self(true, $version, $constraint !== '' ? $constraint : $version);
    }

    public function isInstalled(): bool
    {
        return $this->installed;
    }

    public function version(): string
    {
        return $this->version;
    }

    public function constraint(): string
    {
        return $this->constraint;
    }

    public function describe(): string
    {
        if (!$this->installed) {
            return '—';
        }

        return $this->version !== '' ? $this->version : 'installed';
    }
}
