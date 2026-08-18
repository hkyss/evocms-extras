<?php

namespace hkyss\Extras\Console\Prompt;

use hkyss\Extras\Console\Tty;
use hkyss\Extras\Console\Ui;
use Symfony\Component\Console\Terminal;

final class SelectPrompt
{
    private const CHROME_LINES = 6;

    private const MAX_HEIGHT = 12;

    private Ui $ui;

    private Tty $tty;

    private int $drawn = 0;

    public function __construct(Ui $ui, ?Tty $tty = null)
    {
        $this->ui = $ui;
        $this->tty = $tty ?? new Tty();
    }

    /**
     * @param list<array{value:string,label?:string,hint?:string,search?:string}> $rows
     * @return list<string>|null Chosen values, or null when the user backed out
     */
    public function open(string $title, array $rows, bool $multiple = false, int $viewport = 0): ?array
    {
        if ($rows === []) {
            return [];
        }

        $viewport = $viewport > 0 ? $viewport : (new Terminal())->getHeight();
        $height = max(3, min(self::MAX_HEIGHT, $viewport - self::CHROME_LINES));

        $state = ListState::of($rows, $height);

        $this->tty->enterRawMode();
        $this->hideCursor();

        try {
            return $this->loop($title, $state, $multiple);
        } finally {
            $this->showCursor();
            $this->tty->restore();
        }
    }

    /** @return list<string>|null */
    private function loop(string $title, ListState $state, bool $multiple): ?array
    {
        while (true) {
            $this->draw($title, $state, $multiple);

            $key = $this->tty->readKey();

            switch ($key) {
                case 'up':
                    $state->moveUp();

                    break;
                case 'down':
                case 'tab':
                    $state->moveDown();

                    break;
                case 'pageup':
                    $state->pageUp();

                    break;
                case 'pagedown':
                    $state->pageDown();

                    break;
                case 'space':
                    if ($multiple) {
                        $state->toggle();
                        $state->moveDown();

                        break;
                    }

                    $state->type(' ');

                    break;
                case 'backspace':
                    $state->backspace();

                    break;
                case 'escape':
                    if ($state->filter() !== '') {
                        $state->clearFilter();

                        break;
                    }

                    $this->erase();

                    return null;
                case 'interrupt':
                case 'eof':
                    $this->erase();

                    return null;
                case 'enter':
                    $chosen = $this->resolve($state, $multiple);

                    if ($chosen === []) {
                        break;
                    }

                    $this->erase();

                    return $chosen;
                case 'unknown':
                case 'left':
                case 'right':
                case 'home':
                case 'end':
                case 'delete':
                case '':
                    break;
                default:
                    $state->type($key);

                    break;
            }
        }
    }

    /** @return list<string> */
    private function resolve(ListState $state, bool $multiple): array
    {
        if (!$multiple) {
            $current = $state->current();

            return $current === null ? [] : [$current['value']];
        }

        if ($state->selectedCount() > 0) {
            return $state->selectedValues();
        }

        $current = $state->current();

        return $current === null ? [] : [$current['value']];
    }

    private function draw(string $title, ListState $state, bool $multiple): void
    {
        $this->erase();

        $lines = [];
        $lines[] = $this->ui->strong($title);
        $lines[] = $this->filterLine($state);

        foreach ($state->window() as $row) {
            $lines[] = $this->row($row, $multiple);
        }

        if ($state->isEmpty()) {
            $lines[] = '  ' . $this->ui->dim('nothing matches this filter');
        }

        $lines[] = '';
        $lines[] = $this->ui->dim($this->hint($state, $multiple));

        foreach ($lines as $line) {
            $this->ui->write($line);
        }

        $this->drawn = count($lines);
    }

    private function filterLine(ListState $state): string
    {
        $counts = $state->matches() === $state->total()
            ? (string) $state->total()
            : $state->matches() . '/' . $state->total();

        $typed = $state->filter() === ''
            ? $this->ui->dim('type to filter')
            : $state->filter();

        return '  ' . $this->ui->dim($this->ui->glyph('arrow')) . ' ' . $typed
            . '  ' . $this->ui->dim('(' . $counts . ')');
    }

    /** @param array{option:array{value:string,label:string,hint:string,search:string},active:bool,selected:bool} $row */
    private function row(array $row, bool $multiple): string
    {
        $option = $row['option'];

        $marker = $row['active']
            ? '<fg=cyan>' . $this->ui->glyph('pointer') . '</>'
            : ' ';

        $box = '';

        if ($multiple) {
            $box = ($row['selected']
                ? '<fg=green>' . $this->ui->glyph('checked') . '</>'
                : $this->ui->dim($this->ui->glyph('unchecked'))) . ' ';
        }

        $label = $row['active'] ? '<options=bold>' . $option['label'] . '</>' : $option['label'];
        $hint = $option['hint'] !== '' ? '  ' . $this->ui->dim($option['hint']) : '';

        return ' ' . $marker . ' ' . $box . $label . $hint;
    }

    private function hint(ListState $state, bool $multiple): string
    {
        $keys = [$this->ui->glyph('updown') . ' move'];

        if ($multiple) {
            $keys[] = 'space select';
            $keys[] = 'enter confirm' . ($state->selectedCount() > 0 ? ' (' . $state->selectedCount() . ')' : '');
        } else {
            $keys[] = 'enter choose';
        }

        $keys[] = $state->filter() === '' ? 'esc cancel' : 'esc clear filter';

        return '  ' . implode('  ' . $this->ui->glyph('separator') . '  ', $keys);
    }

    private function erase(): void
    {
        if ($this->drawn === 0) {
            return;
        }

        $this->ui->raw("\e[" . $this->drawn . 'A' . "\e[0J");
        $this->drawn = 0;
    }

    private function hideCursor(): void
    {
        $this->ui->raw("\e[?25l");
    }

    private function showCursor(): void
    {
        $this->ui->raw("\e[?25h");
    }
}
