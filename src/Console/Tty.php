<?php

namespace hkyss\Extras\Console;

final class Tty
{
    private const READ_CHUNK = 32;

    private const KEYS = [
        "\e[A" => 'up',
        "\eOA" => 'up',
        "\e[B" => 'down',
        "\eOB" => 'down',
        "\e[C" => 'right',
        "\eOC" => 'right',
        "\e[D" => 'left',
        "\eOD" => 'left',
        "\e[H" => 'home',
        "\e[F" => 'end',
        "\e[5~" => 'pageup',
        "\e[6~" => 'pagedown',
        "\e[3~" => 'delete',
        "\r" => 'enter',
        "\n" => 'enter',
        "\t" => 'tab',
        "\x7f" => 'backspace',
        "\x08" => 'backspace',
        "\x03" => 'interrupt',
        "\x04" => 'eof',
        "\e" => 'escape',
        ' ' => 'space',
    ];

    /** @var resource */
    private $stream;

    private string $buffer = '';

    private ?string $restoreTo = null;

    private bool $shutdownHooked = false;

    /** @param resource|null $stream */
    public function __construct($stream = null)
    {
        $this->stream = $stream ?? STDIN;
    }

    public static function isAvailable(): bool
    {
        if (DIRECTORY_SEPARATOR === '\\' || !function_exists('shell_exec')) {
            return false;
        }

        if (!defined('STDIN') || !stream_isatty(STDIN) || !stream_isatty(STDOUT)) {
            return false;
        }

        return trim((string) @shell_exec('command -v stty 2>/dev/null')) !== '';
    }

    /** @return array{0:string,1:string} the key, and the unconsumed remainder */
    public static function take(string $bytes): array
    {
        if ($bytes === '') {
            return ['', ''];
        }

        foreach (self::KEYS as $sequence => $name) {
            if (mb_strlen($sequence, '8bit') > 1 && str_starts_with($bytes, $sequence)) {
                return [$name, substr($bytes, strlen($sequence))];
            }
        }

        if (str_starts_with($bytes, "\e") && $bytes !== "\e") {
            return ['unknown', self::skipEscapeSequence($bytes)];
        }

        $first = $bytes[0];

        if (isset(self::KEYS[$first])) {
            return [self::KEYS[$first], substr($bytes, 1)];
        }

        $length = self::characterLength($first);

        return [substr($bytes, 0, $length), substr($bytes, $length)];
    }

    private static function skipEscapeSequence(string $bytes): string
    {
        $length = strlen($bytes);

        for ($i = 1; $i < $length; $i++) {
            $byte = $bytes[$i];

            if ($byte === '[' || $byte === 'O' || ($byte >= '0' && $byte <= '9') || $byte === ';' || $byte === '?') {
                continue;
            }

            return substr($bytes, $i + 1);
        }

        return '';
    }

    private static function characterLength(string $first): int
    {
        $byte = ord($first);

        return match (true) {
            $byte >= 0xF0 => 4,
            $byte >= 0xE0 => 3,
            $byte >= 0xC0 => 2,
            default => 1,
        };
    }

    public function enterRawMode(): void
    {
        if ($this->restoreTo !== null) {
            return;
        }

        $current = trim((string) @shell_exec('stty -g 2>/dev/null'));
        $this->restoreTo = $current !== '' ? $current : 'sane';

        @shell_exec('stty -icanon -echo min 1 time 0 2>/dev/null');

        if ($this->shutdownHooked) {
            return;
        }

        $this->shutdownHooked = true;

        register_shutdown_function(function (): void {
            $this->restore();
        });

        if (function_exists('pcntl_signal') && function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGINT, function (): void {
                $this->restore();
                exit(130);
            });
        }
    }

    public function restore(): void
    {
        if ($this->restoreTo === null) {
            return;
        }

        @shell_exec('stty ' . escapeshellarg($this->restoreTo) . ' 2>/dev/null');
        $this->restoreTo = null;
    }

    public function readKey(): string
    {
        if ($this->buffer === '') {
            $bytes = fread($this->stream, self::READ_CHUNK);

            if ($bytes === false || $bytes === '') {
                return feof($this->stream) ? 'eof' : '';
            }

            $this->buffer = $bytes;
        }

        [$key, $this->buffer] = self::take($this->buffer);

        return $key;
    }
}
