<?php

namespace hkyss\Extras\Console\Prompt;

use hkyss\Extras\Console\Tty;
use hkyss\Extras\Console\Ui;

final class ConfirmPrompt
{
    private Ui $ui;

    private Tty $tty;

    public function __construct(Ui $ui, ?Tty $tty = null)
    {
        $this->ui = $ui;
        $this->tty = $tty ?? new Tty();
    }

    public function ask(string $question, bool $default = true): bool
    {
        $this->ui->inline(
            ' <fg=cyan>' . $this->ui->glyph('pointer') . '</> ' . $question . ' '
            . $this->ui->dim('(' . ($default ? 'Y/n' : 'y/N') . ')') . ' '
        );

        $this->tty->enterRawMode();

        try {
            $answer = $this->read($default);
        } finally {
            $this->tty->restore();
        }

        $this->ui->raw("\r\e[0J");
        $this->ui->write(
            ' <fg=cyan>' . $this->ui->glyph('pointer') . '</> ' . $question . ' '
            . ($answer ? '<fg=green>yes</>' : '<fg=yellow>no</>')
        );

        return $answer;
    }

    private function read(bool $default): bool
    {
        while (true) {
            switch ($this->tty->readKey()) {
                case 'enter':
                    return $default;
                case 'escape':
                case 'interrupt':
                case 'eof':
                    return false;
                case 'y':
                case 'Y':
                    return true;
                case 'n':
                case 'N':
                    return false;
                default:
                    break;
            }
        }
    }
}
