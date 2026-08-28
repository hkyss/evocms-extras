<?php

namespace hkyss\Extras\Manager;

/**
 * One writer at a time. Two composer runs over one core/vendor is how a package directory
 * goes missing from under something still walking it, and the page cannot stop its own
 * buttons: they grey out for the document that pressed them and nowhere else.
 */
class Mutex
{
    private string $path;
    /** @var resource|null */
    private $handle = null;

    public function __construct(string $path)
    {
        $this->path = $path;
    }

    /** A lock that cannot be taken at all must not be the thing that stops an install. */
    public function acquire(): bool
    {
        $handle = @fopen($this->path, 'c');

        if ($handle === false) {
            return true;
        }

        if (!@flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return false;
        }

        $this->handle = $handle;

        return true;
    }

    public function release(): void
    {
        if ($this->handle === null) {
            return;
        }

        @flock($this->handle, LOCK_UN);
        fclose($this->handle);
        $this->handle = null;
    }
}
