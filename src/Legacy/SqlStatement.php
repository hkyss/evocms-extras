<?php

namespace hkyss\Extras\Legacy;

final class SqlStatement
{
    private string $sql;
    private string $hash;

    public function __construct(string $sql, string $hash)
    {
        $this->sql = $sql;
        $this->hash = $hash;
    }

    public function sql(): string
    {
        return $this->sql;
    }

    /** Hashed before prefix substitution, so moving to another prefix does not replay the log. */
    public function hash(): string
    {
        return $this->hash;
    }

    public function summary(int $limit = 70): string
    {
        $flat = trim((string) preg_replace('~\s+~', ' ', $this->sql));

        return mb_strlen($flat) > $limit ? mb_substr($flat, 0, $limit - 1) . '…' : $flat;
    }
}
