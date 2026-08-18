<?php

namespace hkyss\Extras\Console;

use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Terminal;

final class Ui
{
    private const UNICODE = [
        'ok' => '✔',
        'warn' => '▲',
        'fail' => '✖',
        'mutates' => '●',
        'inert' => '·',
        'rule' => '─',
        'branch' => '├─',
        'corner' => '└─',
        'separator' => '·',
        'arrow' => '→',
        'absent' => '—',
        'ellipsis' => '…',
        'pointer' => '❯',
        'checked' => '◉',
        'unchecked' => '○',
        'updown' => '↑↓',
    ];

    private const ASCII = [
        'ok' => '+',
        'warn' => '!',
        'fail' => 'x',
        'mutates' => '*',
        'inert' => '-',
        'rule' => '-',
        'branch' => '|-',
        'corner' => '`-',
        'separator' => '|',
        'arrow' => '->',
        'absent' => '-',
        'ellipsis' => '...',
        'pointer' => '>',
        'checked' => '[x]',
        'unchecked' => '[ ]',
        'updown' => 'up/down',
    ];

    private const MAX_TEXT_WIDTH = 110;

    private OutputInterface $output;

    /** @var array<string, string> */
    private array $glyphs;

    private int $width;

    public function __construct(OutputInterface $output, ?bool $unicode = null, ?int $width = null)
    {
        $this->output = $output;
        $this->glyphs = ($unicode ?? self::terminalSpeaksUnicode()) ? self::UNICODE : self::ASCII;
        $this->width = max(48, min($width ?? (new Terminal())->getWidth(), self::MAX_TEXT_WIDTH));
    }

    public static function terminalSpeaksUnicode(): bool
    {
        $forced = getenv('EXTRAS_ASCII');

        if ($forced !== false && $forced !== '' && $forced !== '0') {
            return false;
        }

        if (DIRECTORY_SEPARATOR !== '\\') {
            return true;
        }

        return getenv('WT_SESSION') !== false
            || getenv('ConEmuANSI') === 'ON'
            || getenv('TERM_PROGRAM') === 'vscode';
    }

    public function glyph(string $name): string
    {
        return $this->glyphs[$name] ?? '';
    }

    public function width(): int
    {
        return $this->width;
    }

    public function isDecorated(): bool
    {
        return $this->output->isDecorated();
    }

    public function dim(string $text): string
    {
        return '<fg=gray>' . $text . '</>';
    }

    public function strong(string $text): string
    {
        return '<options=bold>' . $text . '</>';
    }

    public function accent(string $text): string
    {
        return '<fg=cyan>' . $text . '</>';
    }

    public function absent(): string
    {
        return $this->dim($this->glyph('absent'));
    }

    public function blank(): void
    {
        $this->output->writeln('');
    }

    public function write(string $line = ''): void
    {
        $this->output->writeln($line);
    }

    public function inline(string $text): void
    {
        $this->output->write($text);
    }

    public function raw(string $bytes): void
    {
        $this->output->write($bytes, false, OutputInterface::OUTPUT_RAW);
    }

    public function heading(string $title, string $subtitle = ''): void
    {
        $this->blank();
        $this->write($this->strong($title));

        if ($subtitle !== '') {
            $this->write($this->dim($subtitle));
        }

        $rule = min($this->width, max(mb_strlen($title), mb_strlen($subtitle)));

        $this->write($this->dim(str_repeat($this->glyph('rule'), $rule)));
        $this->blank();
    }

    public function section(string $label): void
    {
        $this->write($this->accent($label));
    }

    /** @param list<array{0:string,1:string}> $rows */
    public function details(array $rows, int $indent = 2): void
    {
        if ($rows === []) {
            return;
        }

        $keyWidth = 0;

        foreach ($rows as [$key, $_]) {
            $keyWidth = max($keyWidth, mb_strlen($key));
        }

        $pad = str_repeat(' ', $indent);

        foreach ($rows as [$key, $value]) {
            $this->write($pad . $this->dim($this->pad($key, $keyWidth)) . '  ' . $value);
        }
    }

    /**
     * @param list<string>            $headers
     * @param list<list<string>>      $rows
     */
    public function table(array $headers, array $rows): void
    {
        $table = new Table($this->output);

        $style = clone Table::getStyleDefinition('box');
        $style->setCellHeaderFormat('%s');

        $table->setStyle($style);

        if ($headers !== []) {
            $table->setHeaders(array_map(fn (string $h): string => $this->strong($h), $headers));
        }

        $table->setRows($rows);
        $table->render();
    }

    /** @param list<array{level:string,name:string,detail:string}> $checks */
    public function checks(array $checks, int $indent = 2): void
    {
        if ($checks === []) {
            return;
        }

        $nameWidth = 0;

        foreach ($checks as $check) {
            $nameWidth = max($nameWidth, mb_strlen($check['name']));
        }

        $pad = str_repeat(' ', $indent);
        $glyphWidth = mb_strlen($this->glyph('ok')) + 1;
        $hanging = $pad . str_repeat(' ', $glyphWidth + $nameWidth + 2);

        foreach ($checks as $check) {
            $lines = $this->wrap($check['detail'], $this->width - mb_strlen($hanging));
            $head = array_shift($lines) ?? '';

            $this->write(
                $pad . $this->mark($check['level']) . ' '
                . $this->pad($check['name'], $nameWidth) . '  ' . $head
            );

            foreach ($lines as $line) {
                $this->write($hanging . $line);
            }
        }
    }

    public function pad(string $text, int $width): string
    {
        return $text . str_repeat(' ', max(0, $width - mb_strlen($text)));
    }

    public function mark(string $level): string
    {
        return match ($level) {
            'ok' => '<fg=green>' . $this->glyph('ok') . '</>',
            'warn' => '<fg=yellow>' . $this->glyph('warn') . '</>',
            'fail' => '<fg=red>' . $this->glyph('fail') . '</>',
            default => ' ',
        };
    }

    public function treeItem(string $text, bool $last, bool $mutates, int $indent = 2): void
    {
        $marker = $mutates
            ? '<fg=yellow>' . $this->glyph('mutates') . '</>'
            : $this->dim($this->glyph('inert'));

        $this->write(
            str_repeat(' ', $indent)
            . $this->dim($last ? $this->glyph('corner') : $this->glyph('branch'))
            . ' ' . $marker . ' ' . ($mutates ? $text : $this->dim($text))
        );
    }

    /** @param list<string> $parts */
    public function footer(array $parts): void
    {
        $parts = array_values(array_filter($parts, static fn (string $p): bool => $p !== ''));

        if ($parts === []) {
            return;
        }

        $this->blank();
        $this->write($this->dim(implode(' ' . $this->glyph('separator') . ' ', $parts)));
    }

    public function banner(bool $ok, string $message): void
    {
        $this->blank();
        $this->write(
            $this->mark($ok ? 'ok' : 'fail') . ' '
            . ($ok ? '<fg=green>' : '<fg=red>') . $message . '</>'
        );
    }

    public function note(string $level, string $message, int $indent = 0): void
    {
        foreach ($this->wrap($message, $this->width - $indent - 2) as $i => $line) {
            $this->write(
                str_repeat(' ', $indent)
                . ($i === 0 ? $this->mark($level) : ' ')
                . ' ' . $line
            );
        }
    }

    /** @return list<string> */
    public function wrap(string $text, int $width): array
    {
        $width = max(20, $width);

        if (mb_strlen($text) <= $width) {
            return [$text];
        }

        $lines = [];
        $current = '';

        foreach (preg_split('~\s+~u', $text) ?: [] as $word) {
            if ($current === '') {
                $current = $word;

                continue;
            }

            if (mb_strlen($current) + 1 + mb_strlen($word) <= $width) {
                $current .= ' ' . $word;

                continue;
            }

            $lines[] = $current;
            $current = $word;
        }

        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines;
    }

    public function truncate(string $text, int $limit): string
    {
        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        $ellipsis = $this->glyph('ellipsis');

        return rtrim(mb_substr($text, 0, max(1, $limit - mb_strlen($ellipsis)))) . $ellipsis;
    }
}
