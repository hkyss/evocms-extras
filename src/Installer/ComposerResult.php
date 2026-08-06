<?php

namespace hkyss\Extras\Installer;

final class ComposerResult
{
    private int $exitCode;
    /** @var list<string> */
    private array $output;

    /** @param list<string> $output */
    public function __construct(int $exitCode, array $output = [])
    {
        $this->exitCode = $exitCode;
        $this->output = $output;
    }

    public function isSuccessful(): bool
    {
        return $this->exitCode === 0;
    }

    public function exitCode(): int
    {
        return $this->exitCode;
    }

    /** @return list<string> */
    public function output(): array
    {
        return $this->output;
    }

    /** @return list<string> The reason Composer refused is usually at the end. */
    public function tail(int $lines = 12): array
    {
        return array_slice($this->output, -$lines);
    }
}
