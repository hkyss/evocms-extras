<?php

namespace hkyss\Extras\Legacy;

/** Splits setup.data.sql into individually hashed statements; the file is an append-only log. */
class SqlScript
{
    /** @var list<SqlStatement> */
    private array $statements;

    /** @param list<SqlStatement> $statements */
    private function __construct(array $statements)
    {
        $this->statements = $statements;
    }

    public static function parse(string $contents, string $tablePrefix = ''): self
    {
        $statements = [];

        foreach (self::split($contents) as $raw) {
            $sql = trim($raw);

            if ($sql === '') {
                continue;
            }

            $statements[] = new SqlStatement(
                str_replace('{PREFIX}', $tablePrefix, $sql),
                hash('sha256', $sql)
            );
        }

        return new self($statements);
    }

    /** @return list<SqlStatement> */
    public function statements(): array
    {
        return $this->statements;
    }

    /**
     * @param list<string> $appliedHashes
     * @return list<SqlStatement>
     */
    public function pending(array $appliedHashes): array
    {
        $applied = array_flip($appliedHashes);

        return array_values(array_filter(
            $this->statements,
            static fn (SqlStatement $s) => !isset($applied[$s->hash()])
        ));
    }

    public function isEmpty(): bool
    {
        return $this->statements === [];
    }

    /**
     * Splits on `;` without breaking string literals or comments.
     *
     * @return list<string>
     */
    private static function split(string $contents): array
    {
        $statements = [];
        $buffer = '';
        $length = strlen($contents);
        $quote = null;

        for ($i = 0; $i < $length; $i++) {
            $char = $contents[$i];
            $next = $i + 1 < $length ? $contents[$i + 1] : '';

            if ($quote !== null) {
                $buffer .= $char;

                if ($char === '\\' && $next !== '') {
                    $buffer .= $next;
                    $i++;
                    continue;
                }

                if ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === '#' || ($char === '-' && $next === '-')) {
                while ($i < $length && $contents[$i] !== "\n") {
                    $i++;
                }

                $buffer .= "\n";
                continue;
            }

            if ($char === '/' && $next === '*') {
                $end = strpos($contents, '*/', $i + 2);
                $i = $end === false ? $length : $end + 1;
                continue;
            }

            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
                $buffer .= $char;
                continue;
            }

            if ($char === ';') {
                $statements[] = $buffer;
                $buffer = '';
                continue;
            }

            $buffer .= $char;
        }

        if (trim($buffer) !== '') {
            $statements[] = $buffer;
        }

        return $statements;
    }
}
