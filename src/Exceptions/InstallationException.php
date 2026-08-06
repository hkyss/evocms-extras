<?php

namespace hkyss\Extras\Exceptions;

class InstallationException extends ExtrasException
{
    /** @var list<string> */
    private array $output;

    /** @param list<string> $output */
    public function __construct(string $message, array $output = [])
    {
        parent::__construct($message);
        $this->output = $output;
    }

    /** @param list<string> $output */
    public static function composerFailed(string $coordinate, int $exitCode, array $output = []): self
    {
        return new self(
            "Composer exited with code {$exitCode} while installing '{$coordinate}'; the manifest was rolled back",
            $output
        );
    }

    public static function unreadableArchive(string $coordinate, string $reason): self
    {
        return new self("Cannot read archive for '{$coordinate}': {$reason}");
    }

    /** @return list<string> */
    public function output(): array
    {
        return $this->output;
    }
}
