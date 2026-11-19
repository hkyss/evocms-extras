<?php

namespace hkyss\Extras\Tests\Support;

use hkyss\Extras\Support\Http;

/** Answers every download with a zip of the given files, wrapped the way GitHub wraps an archive. */
class ArchiveHttp extends Http
{
    /** @param array<string,string> $files */
    public function __construct(private array $files)
    {
        parent::__construct();
    }

    /** @param array<string,string> $files */
    public function serve(array $files): void
    {
        $this->files = $files;
    }

    public function download(string $url, string $destination, bool $github = false): bool
    {
        $zip = new \ZipArchive();
        $zip->open($destination, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        foreach ($this->files as $relative => $contents) {
            $zip->addFromString('extra-main/' . $relative, $contents);
        }

        return $zip->close();
    }
}
