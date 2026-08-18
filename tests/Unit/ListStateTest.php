<?php

namespace hkyss\Extras\Tests\Unit;

use hkyss\Extras\Console\Prompt\ListState;
use hkyss\Extras\Console\Tty;
use PHPUnit\Framework\TestCase;

class ListStateTest extends TestCase
{
    /** @return list<array{value:string,label:string,hint:string}> */
    private static function rows(int $count = 5): array
    {
        $rows = [];

        for ($i = 1; $i <= $count; $i++) {
            $rows[] = ['value' => "vendor/pkg{$i}", 'label' => "vendor/pkg{$i}", 'hint' => 'composer'];
        }

        return $rows;
    }

    public function testWindowNeverExceedsItsHeight(): void
    {
        $state = ListState::of(self::rows(50), 7);

        self::assertCount(7, $state->window());

        for ($i = 0; $i < 30; $i++) {
            $state->moveDown();
            self::assertLessThanOrEqual(7, count($state->window()));
        }
    }

    public function testActiveRowStaysInsideTheWindow(): void
    {
        $state = ListState::of(self::rows(40), 5);

        for ($i = 0; $i < 39; $i++) {
            $state->moveDown();

            $active = array_filter($state->window(), static fn (array $row): bool => $row['active']);

            self::assertCount(1, $active, "cursor left the viewport after {$i} moves");
        }
    }

    public function testMovementWrapsAtBothEnds(): void
    {
        $state = ListState::of(self::rows(3), 3);

        $state->moveUp();
        self::assertSame('vendor/pkg3', $state->current()['value']);

        $state->moveDown();
        self::assertSame('vendor/pkg1', $state->current()['value']);
    }

    public function testFilterKeepsTheHighlightedOptionWhenItSurvives(): void
    {
        $state = ListState::of([
            ['value' => 'evolution-cms-extras/doclister'],
            ['value' => 'evocms-community/DLInstagram'],
            ['value' => 'vvvladv/evo-swagger'],
        ], 10);

        $state->moveDown();
        self::assertSame('evocms-community/DLInstagram', $state->current()['value']);

        $state->type('dl');

        self::assertSame(
            'evocms-community/DLInstagram',
            $state->current()['value'],
            'a filter that still matches the active row must not move the cursor'
        );
        self::assertSame(1, $state->matches());
    }

    public function testFilterIsCaseInsensitiveAndSearchesTheDescription(): void
    {
        $state = ListState::of([
            ['value' => 'a/one', 'hint' => 'Instagram controller'],
            ['value' => 'b/two', 'hint' => 'something else'],
        ], 10);

        $state->type('INSTAGRAM');

        self::assertSame(1, $state->matches());
        self::assertSame('a/one', $state->current()['value']);
    }

    public function testBackspaceRestoresTheFullList(): void
    {
        $state = ListState::of(self::rows(4), 10);

        $state->type('pkg2');
        self::assertSame(1, $state->matches());

        foreach (str_split('pkg2') as $_) {
            $state->backspace();
        }

        self::assertSame(4, $state->matches());
        self::assertSame('', $state->filter());
    }

    public function testEmptyFilterResultLeavesNoCurrentOption(): void
    {
        $state = ListState::of(self::rows(3), 10);

        $state->type('nothing-matches-this');

        self::assertTrue($state->isEmpty());
        self::assertNull($state->current());

        $state->moveDown();
        $state->moveUp();

        self::assertNull($state->current());
    }

    public function testSelectionFollowsListOrderNotClickOrder(): void
    {
        $state = ListState::of(self::rows(4), 10);

        $state->pageDown();
        $state->toggle();
        $state->moveUp();
        $state->moveUp();
        $state->toggle();

        self::assertSame(['vendor/pkg2', 'vendor/pkg4'], $state->selectedValues());
    }

    public function testToggleRemovesAnAlreadySelectedOption(): void
    {
        $state = ListState::of(self::rows(3), 10);

        $state->toggle();
        self::assertSame(1, $state->selectedCount());

        $state->moveUp();
        $state->moveDown();
        $state->toggle();

        self::assertSame(0, $state->selectedCount());
    }

    public function testSelectionSurvivesFiltering(): void
    {
        $state = ListState::of(self::rows(5), 10);

        $state->toggle();
        $state->type('pkg3');
        $state->toggle();
        $state->clearFilter();

        self::assertSame(['vendor/pkg1', 'vendor/pkg3'], $state->selectedValues());
    }

    /** @dataProvider keys */
    public function testKeyDecoding(string $bytes, string $expected, string $rest = ''): void
    {
        self::assertSame([$expected, $rest], Tty::take($bytes));
    }

    public function testABurstOfKeysIsConsumedOneAtATime(): void
    {
        $keys = [];
        $bytes = "\e[B\e[Bsw\r";

        while ($bytes !== '') {
            [$key, $bytes] = Tty::take($bytes);
            $keys[] = $key;
        }

        self::assertSame(['down', 'down', 's', 'w', 'enter'], $keys);
    }

    public function testAnUnknownSequenceIsSkippedWholeAndNotTypedIntoTheFilter(): void
    {
        [$key, $rest] = Tty::take("\e[24;5Rdoc");

        self::assertSame('unknown', $key);
        self::assertSame('doc', $rest, 'the reply must be swallowed, not left to be typed');
    }

    /** @dataProvider unattendedValues */
    public function testCiTurnsInteractivityOff(string $value, bool $expected): void
    {
        $previous = getenv('CI');
        $value === '' ? putenv('CI') : putenv("CI={$value}");

        try {
            self::assertSame($expected, Tty::runsUnattended());
        } finally {
            $previous === false ? putenv('CI') : putenv("CI={$previous}");
        }
    }

    /** @return array<string, array{string, bool}> */
    public static function unattendedValues(): array
    {
        return [
            'unset' => ['', false],
            'true' => ['true', true],
            'one' => ['1', true],
            'github actions' => ['true', true],
            'explicitly off' => ['false', false],
            'zero' => ['0', false],
        ];
    }

    /** @return array<string, array{string, string}> */
    public static function keys(): array
    {
        return [
            'arrow up' => ["\e[A", 'up'],
            'arrow down' => ["\e[B", 'down'],
            'application-mode up' => ["\eOA", 'up'],
            'enter' => ["\r", 'enter'],
            'newline' => ["\n", 'enter'],
            'backspace' => ["\x7f", 'backspace'],
            'ctrl-c' => ["\x03", 'interrupt'],
            'escape' => ["\e", 'escape'],
            'space' => [' ', 'space'],
            'plain text' => ['doc', 'd', 'oc'],
            'cyrillic' => ['док', 'д', 'ок'],
            'unhandled sequence' => ["\e[24;5R", 'unknown'],
            'nothing' => ['', ''],
        ];
    }
}
