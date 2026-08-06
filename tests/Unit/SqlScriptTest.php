<?php

namespace hkyss\Extras\Tests\Unit;

use hkyss\Extras\Legacy\SqlScript;
use PHPUnit\Framework\TestCase;

class SqlScriptTest extends TestCase
{
    private function fixture(): string
    {
        return (string) file_get_contents(__DIR__ . '/../Fixtures/legacy/setup.data.sql');
    }

    public function testSplitsRealScriptIntoStatements(): void
    {
        $script = SqlScript::parse($this->fixture(), 'evo_');

        self::assertCount(8, $script->statements());
        self::assertStringStartsWith('CREATE TABLE IF NOT EXISTS', $script->statements()[0]->sql());
    }

    public function testSubstitutesTablePrefix(): void
    {
        $script = SqlScript::parse($this->fixture(), 'evo_');

        foreach ($script->statements() as $statement) {
            self::assertStringNotContainsString('{PREFIX}', $statement->sql());
        }

        self::assertStringContainsString('`evo_pagebuilder`', $script->statements()[0]->sql());
    }

    public function testStripsHashComments(): void
    {
        foreach (SqlScript::parse($this->fixture(), '')->statements() as $statement) {
            self::assertStringNotContainsString('# Upgrading', $statement->sql());
            self::assertStringNotContainsString('# Fix', $statement->sql());
        }
    }

    public function testPendingExcludesAlreadyApplied(): void
    {
        $script = SqlScript::parse($this->fixture(), 'evo_');
        $applied = array_map(static fn ($s) => $s->hash(), array_slice($script->statements(), 0, 3));

        self::assertCount(5, $script->pending($applied));
        self::assertCount(0, $script->pending(array_map(
            static fn ($s) => $s->hash(),
            $script->statements()
        )));
    }

    public function testHashIsIndependentOfTablePrefix(): void
    {
        $a = SqlScript::parse($this->fixture(), 'evo_');
        $b = SqlScript::parse($this->fixture(), 'modx_');

        self::assertSame(
            array_map(static fn ($s) => $s->hash(), $a->statements()),
            array_map(static fn ($s) => $s->hash(), $b->statements())
        );
    }

    public function testDoesNotSplitInsideStringLiterals(): void
    {
        $script = SqlScript::parse(
            "INSERT INTO {PREFIX}t (p) VALUES ('&a=x;y;z');\nALTER TABLE {PREFIX}t ADD c INT;",
            'evo_'
        );

        self::assertCount(2, $script->statements());
        self::assertStringContainsString("'&a=x;y;z'", $script->statements()[0]->sql());
    }

    public function testHandlesEscapedQuotes(): void
    {
        self::assertCount(2, SqlScript::parse("INSERT INTO t VALUES ('a\\'; b');\nSELECT 1;", '')->statements());
    }

    public function testEmptyScriptHasNoStatements(): void
    {
        self::assertTrue(SqlScript::parse("# only a comment\n", '')->isEmpty());
    }
}
