<?php

namespace hkyss\Extras\Manager;

/**
 * Two composer runs over one core/vendor is how a package directory goes missing from under
 * something still walking it, and the page's buttons grey out only for the document that pressed them.
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

    public function acquire(): bool
    {
        $directory = dirname($this->path);

        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        $handle = $this->open();

        if ($handle === false) {
            return false;
        }

        if (!@flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return false;
        }

        $this->handle = $handle;

        return true;
    }

    /**
     * The console and the module run as different users, so a lock file the other one created is opened for reading, which is all flock needs.
     *
     * @return resource|false
     */
    private function open()
    {
        $created = !is_file($this->path);
        $handle = @fopen($this->path, 'c');

        if ($handle === false) {
            return @fopen($this->path, 'r');
        }

        if ($created) {
            @chmod($this->path, 0666);
        }

        return $handle;
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
