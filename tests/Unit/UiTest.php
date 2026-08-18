<?php

namespace hkyss\Extras\Tests\Unit;

use hkyss\Extras\Console\Ui;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

class UiTest extends TestCase
{
    public function testAsciiModeEmitsNothingOutsideAscii(): void
    {
        $output = new BufferedOutput();
        $ui = new Ui($output, false, 80);

        $ui->heading('extra:doctor', 'can this installation install extras?');
        $ui->section('platform');
        $ui->checks([
            ['level' => 'ok', 'name' => 'PHP version', 'detail' => '8.2.4'],
            ['level' => 'warn', 'name' => 'GITHUB_PAT', 'detail' => 'not set'],
            ['level' => 'fail', 'name' => 'ext-zip', 'detail' => 'missing'],
        ]);
        $ui->treeItem('require vendor/package', false, true);
        $ui->treeItem('keep assets/plugins/thing.php', true, false);
        $ui->footer(['3 checks', '1 to fix']);
        $ui->banner(false, 'nope');

        $rendered = $output->fetch();

        self::assertSame(
            $rendered,
            (string) preg_replace('~[^\x00-\x7F]~u', '', $rendered),
            'EXTRAS_ASCII output must survive a terminal that only speaks ASCII'
        );
    }

    public function testUnicodeModeUsesGlyphs(): void
    {
        $ui = new Ui(new BufferedOutput(), true, 80);

        self::assertSame('✔', $ui->glyph('ok'));
        self::assertSame('▲', $ui->glyph('warn'));
        self::assertSame('✖', $ui->glyph('fail'));
    }

    public function testMarkupNeverLeaksWhenColourIsOff(): void
    {
        $output = new BufferedOutput();
        $ui = new Ui($output, true, 80);

        $ui->details([['Format', $ui->accent('composer')], ['Source', $ui->dim('Bundled snapshot')]]);
        $ui->banner(true, 'Everything checks out.');

        $rendered = $output->fetch();

        self::assertStringNotContainsString('<fg=', $rendered);
        self::assertStringNotContainsString('</>', $rendered);
        self::assertStringContainsString('composer', $rendered);
    }

    public function testDetailsAlignValuesOnTheWidestKey(): void
    {
        $output = new BufferedOutput();
        $ui = new Ui($output, true, 80);

        $ui->details([['Format', 'composer'], ['Default ref', '5.7.1']], 0);

        $lines = array_values(array_filter(explode("\n", $output->fetch())));

        self::assertSame(
            mb_strpos($lines[0], 'composer'),
            mb_strpos($lines[1], '5.7.1'),
            'values must start in the same column'
        );
    }

    public function testDetailsAlignEvenWhenKeysAreNotAscii(): void
    {
        $output = new BufferedOutput();
        $ui = new Ui($output, true, 80);

        $rows = [['↑↓ type', 'move'], ['space enter', 'confirm'], ['версия', 'v1']];
        $ui->details($rows, 0);

        $lines = array_values(array_filter(explode("\n", $output->fetch())));
        $columns = [];

        foreach ($lines as $index => $line) {
            $columns[] = mb_strpos($line, $rows[$index][1]);
        }

        self::assertSame(
            [mb_strlen('space enter') + 2],
            array_values(array_unique($columns)),
            'every value must start in the same column as the widest key'
        );
    }

    public function testPadMeasuresCharacters(): void
    {
        $ui = new Ui(new BufferedOutput(), true, 80);

        self::assertSame(10, mb_strlen($ui->pad('↑↓', 10)));
        self::assertSame(10, mb_strlen($ui->pad('версия', 10)));
        self::assertSame('exact', $ui->pad('exact', 3), 'never truncates, only pads');
    }

    public function testWrapCountsCharactersNotBytes(): void
    {
        $ui = new Ui(new BufferedOutput(), true, 80);
        $text = 'Консольный менеджер расширений для Evolution CMS с каталогом и установкой';

        foreach ($ui->wrap($text, 30) as $line) {
            self::assertLessThanOrEqual(30, mb_strlen($line));
        }

        self::assertSame($text, implode(' ', $ui->wrap($text, 30)));
    }

    public function testWrapLeavesShortTextAlone(): void
    {
        $ui = new Ui(new BufferedOutput(), true, 80);

        self::assertSame(['short enough'], $ui->wrap('short enough', 40));
    }

    public function testTruncateOnlyMarksWhatItCut(): void
    {
        $ui = new Ui(new BufferedOutput(), true, 80);

        self::assertSame('exact', $ui->truncate('exact', 5));
        self::assertSame('стоп…', $ui->truncate('стоп тут длиннее', 5));
        self::assertLessThanOrEqual(10, mb_strlen($ui->truncate(str_repeat('a', 40), 10)));
    }

    public function testAsciiTruncateUsesThreeDots(): void
    {
        $ui = new Ui(new BufferedOutput(), false, 80);

        self::assertStringEndsWith('...', $ui->truncate(str_repeat('a', 40), 10));
    }
}
