<?php

namespace hkyss\Extras\Console\Prompt;

use hkyss\Extras\Console\Ui;
use Throwable;

final class Spinner
{
    private const FRAMES_UNICODE = ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏'];

    private const FRAMES_ASCII = ['-', '\\', '|', '/'];

    private const INTERVAL_US = 80000;

    private Ui $ui;

    public function __construct(Ui $ui)
    {
        $this->ui = $ui;
    }

    /**
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public function spin(string $label, callable $work)
    {
        if (!$this->canFork()) {
            $this->ui->write(' ' . $this->ui->dim($label . $this->ui->glyph('ellipsis')));

            return $work();
        }

        $pid = pcntl_fork();

        if ($pid === -1) {
            $this->ui->write(' ' . $this->ui->dim($label . $this->ui->glyph('ellipsis')));

            return $work();
        }

        if ($pid === 0) {
            $this->animate($label);

            exit(0);
        }

        try {
            return $work();
        } finally {
            $this->stop($pid);
        }
    }

    private function animate(string $label): void
    {
        $frames = $this->ui->glyph('pointer') === '>' ? self::FRAMES_ASCII : self::FRAMES_UNICODE;

        for ($index = 0;; $index++) {
            $this->ui->raw("\r\e[0K " . $frames[$index % count($frames)] . ' ' . $label);
            usleep(self::INTERVAL_US);
        }
    }

    private function stop(int $pid): void
    {
        @posix_kill($pid, SIGKILL);
        @pcntl_waitpid($pid, $status);

        $this->ui->raw("\r\e[0K");
    }

    private function canFork(): bool
    {
        try {
            return DIRECTORY_SEPARATOR !== '\\'
                && function_exists('pcntl_fork')
                && function_exists('posix_kill')
                && $this->ui->isDecorated();
        } catch (Throwable) {
            return false;
        }
    }
}
