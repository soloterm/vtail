<?php

namespace SoloTerm\Vtail\Terminal;

use ReflectionClass;
use RuntimeException;
use Symfony\Component\Console\Terminal as SymfonyTerminal;

class Terminal
{
    protected ?string $initialTtyMode = null;

    protected SymfonyTerminal $terminal;

    public function __construct()
    {
        $this->terminal = new SymfonyTerminal;
    }

    /**
     * Set the TTY mode.
     */
    public function setTty(string $mode): void
    {
        $this->initialTtyMode ??= $this->exec('stty -g');

        $this->exec("stty $mode");
    }

    /**
     * Set the terminal to raw mode.
     */
    public function setRawMode(): void
    {
        $this->setTty('raw -echo');
    }

    /**
     * Restore the initial TTY mode.
     */
    public function restoreTty(): void
    {
        if (isset($this->initialTtyMode)) {
            $this->exec("stty {$this->initialTtyMode}");

            $this->initialTtyMode = null;
        }
    }

    /**
     * Get the number of columns in the terminal.
     */
    public function cols(): int
    {
        return $this->terminal->getWidth();
    }

    /**
     * Get the number of lines in the terminal.
     */
    public function lines(): int
    {
        return $this->terminal->getHeight();
    }

    /**
     * Reinitialize terminal dimensions after resize (SIGWINCH).
     *
     * Uses reflection to call Symfony Terminal's private initDimensions()
     * method, which re-queries the terminal size. This is necessary because
     * Symfony Terminal caches dimensions and doesn't expose a public reset.
     */
    public function initDimensions(): void
    {
        (new ReflectionClass($this->terminal))
            ->getMethod('initDimensions')
            ->invoke($this->terminal);
    }

    /**
     * Execute the given command and return the output.
     */
    protected function exec(string $command): string
    {
        $process = proc_open($command, [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);

        if (! $process) {
            throw new RuntimeException('Failed to create process.');
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        $code = proc_close($process);

        if ($code !== 0 || $stdout === false) {
            throw new RuntimeException(trim($stderr ?: "Unknown error (code: $code)"), $code);
        }

        return $stdout;
    }

    public function enterAlternateScreen(): void
    {
        echo "\e[?1049h\e[2J";
    }

    public function exitAlternateScreen(): void
    {
        echo "\e[?1049l";
    }

    public function hideCursor(): void
    {
        echo "\e[?25l";
    }

    public function showCursor(): void
    {
        echo "\e[?25h";
    }

    public function moveCursor(int $row, int $col): void
    {
        echo "\e[{$row};{$col}H";
    }

    public function isInteractive(): bool
    {
        return posix_isatty(STDIN) && posix_isatty(STDOUT);
    }
}
