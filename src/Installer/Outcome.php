<?php

namespace hkyss\Extras\Installer;

final class Outcome
{
    private bool $successful;
    private bool $nothingHappened = false;
    private string $message;
    /** @var list<string> */
    private array $notes;
    /** @var list<string> */
    private array $output;

    /**
     * @param list<string> $notes  What was left behind and needs attention.
     * @param list<string> $output Raw output of an external tool, when one ran.
     */
    private function __construct(bool $successful, string $message, array $notes = [], array $output = [])
    {
        $this->successful = $successful;
        $this->message = $message;
        $this->notes = $notes;
        $this->output = $output;
    }

    /**
     * @param list<string> $notes
     * @param list<string> $output
     */
    public static function success(string $message, array $notes = [], array $output = []): self
    {
        return new self(true, $message, $notes, $output);
    }

    /**
     * @param list<string> $notes
     * @param list<string> $output
     */
    public static function failure(string $message, array $notes = [], array $output = []): self
    {
        return new self(false, $message, $notes, $output);
    }

    public static function noop(string $message): self
    {
        $outcome = new self(true, $message);
        $outcome->nothingHappened = true;

        return $outcome;
    }

    public function isSuccessful(): bool
    {
        return $this->successful;
    }

    /** Successful and the site is untouched, which is not the same answer as done. */
    public function isNoop(): bool
    {
        return $this->nothingHappened;
    }

    public function message(): string
    {
        return $this->message;
    }

    /** @return list<string> */
    public function notes(): array
    {
        return $this->notes;
    }

    /** @return list<string> */
    public function output(): array
    {
        return $this->output;
    }

    public function withNote(string $note): self
    {
        $clone = clone $this;
        $clone->notes[] = $note;

        return $clone;
    }
}
