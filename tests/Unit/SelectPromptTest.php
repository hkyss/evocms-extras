<?php

namespace hkyss\Extras\Tests\Unit;

use hkyss\Extras\Console\Prompt\SelectPrompt;
use hkyss\Extras\Console\Tty;
use hkyss\Extras\Console\Ui;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

class SelectPromptTest extends TestCase
{
    /**
     * @param list<string> $keys
     * @return list<string>|null
     */
    private function drive(array $keys, bool $multiple, ?BufferedOutput $output = null): ?array
    {
        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);
        fwrite($stream, implode('', $keys));
        rewind($stream);

        $output ??= new BufferedOutput();
        $prompt = new SelectPrompt(new Ui($output, true, 80), new Tty($stream));

        return $prompt->open('Pick one', [
            ['value' => 'evolution-cms-extras/doclister', 'hint' => 'composer'],
            ['value' => 'evocms-community/DLInstagram', 'hint' => 'legacy'],
            ['value' => 'vvvladv/evo-swagger', 'hint' => 'composer'],
        ], $multiple, 24);
    }

    public function testEnterTakesTheHighlightedOption(): void
    {
        self::assertSame(['evolution-cms-extras/doclister'], $this->drive(["\r"], false));
    }

    public function testArrowKeysMoveTheHighlight(): void
    {
        self::assertSame(
            ['vvvladv/evo-swagger'],
            $this->drive(["\e[B", "\e[B", "\r"], false)
        );
    }

    public function testTypingFiltersAndEnterTakesTheMatch(): void
    {
        self::assertSame(
            ['vvvladv/evo-swagger'],
            $this->drive(['s', 'w', 'a', "\r"], false)
        );
    }

    public function testEscapeClearsTheFilterBeforeItCancels(): void
    {
        self::assertNull($this->drive(['s', 'w', "\e", "\e"], false));
    }

    public function testEscapeOnAnUnfilteredListCancels(): void
    {
        self::assertNull($this->drive(["\e"], false));
    }

    public function testCtrlCCancels(): void
    {
        self::assertNull($this->drive(["\x03"], false));
    }

    public function testExhaustedInputCancels(): void
    {
        self::assertNull($this->drive([], false));
    }

    public function testSpaceTicksSeveralOptions(): void
    {
        self::assertSame(
            ['evolution-cms-extras/doclister', 'evocms-community/DLInstagram'],
            $this->drive([' ', ' ', "\r"], true)
        );
    }

    public function testEnterWithoutTickingTakesTheHighlightedRow(): void
    {
        self::assertSame(['evocms-community/DLInstagram'], $this->drive(["\e[B", "\r"], true));
    }

    public function testSpaceDoesNotFilterInMultiSelect(): void
    {
        self::assertSame(
            ['evolution-cms-extras/doclister', 'evocms-community/DLInstagram'],
            $this->drive([' ', ' ', "\r"], true)
        );
    }

    public function testEveryDrawnFrameIsErasedBeforeTheNextOne(): void
    {
        $output = new BufferedOutput();
        $this->drive(["\e[B", 'swa', "\r"], false, $output);

        $rendered = $output->fetch();

        $parts = preg_split('~\e\[(\d+)A\e\[0J~', $rendered, -1, PREG_SPLIT_DELIM_CAPTURE);
        self::assertIsArray($parts);
        self::assertGreaterThan(1, count($parts), 'nothing was erased at all');

        for ($i = 1; $i < count($parts); $i += 2) {
            $drawn = substr_count($parts[$i - 1], "\n");

            self::assertSame(
                $drawn,
                (int) $parts[$i],
                'an erase must span exactly the lines its frame drew, or it eats the scrollback'
            );
        }

        self::assertStringEndsWith("\e[?25h", $rendered, 'the cursor must be visible again');
    }
}
