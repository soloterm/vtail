<?php

/**
 * @author Aaron Francis <aaron@tryhardstudios.com>
 *
 * @link https://aaronfrancis.com
 * @link https://x.com/aarondfrancis
 */

namespace SoloTerm\Vtail;

use SoloTerm\Vtail\Formatting\AnsiAware;
use SoloTerm\Vtail\Formatting\LineCollection;
use SoloTerm\Vtail\Formatting\LogFormatter;
use SoloTerm\Vtail\Input\KeyCodes;
use SoloTerm\Vtail\Input\KeyPressListener;
use SoloTerm\Vtail\Terminal\Terminal;

class Application
{
    /**
     * Frame interval in microseconds (40 FPS = 25ms = 25000µs).
     */
    protected const FRAME_INTERVAL_US = 25000;

    protected Terminal $terminal;

    protected KeyPressListener $listener;

    protected LogFormatter $formatter;

    protected string $file;

    /**
     * @var resource|null
     */
    protected $tailProcess = null;

    /**
     * @var resource|null
     */
    protected $tailPipes = null;

    protected bool $running = true;

    protected bool $hideVendor = false;

    protected bool $wrapLines = true;

    protected bool $following = true;

    protected int $scrollIndex = 0;

    /**
     * Raw lines from the log file.
     *
     * @var array<string>
     */
    protected array $lines = [];

    /**
     * Line collection with metadata.
     */
    protected ?LineCollection $lineCollection = null;

    protected bool $dirty = true;

    protected int $tailLines = 100;

    protected int $maxLines = 1000;

    protected int $trimThreshold = 1200;

    protected int $lastFormattedIndex = 0;

    protected bool $needsRebuild = true;

    protected int $lastContentWidth = 0;

    protected bool $lastWrapLines = true;

    public function __construct(
        string $file,
        bool $hideVendor = false,
        bool $wrapLines = true,
        int $tailLines = 100,
        int $maxLines = 1000
    ) {
        $this->file = $file;
        $this->hideVendor = $hideVendor;
        $this->wrapLines = $wrapLines;
        $this->tailLines = $tailLines;
        $this->maxLines = $maxLines;
        $this->trimThreshold = (int) ($maxLines * 1.2);
        $this->lastWrapLines = $wrapLines;

        $this->terminal = new Terminal;
        $this->listener = new KeyPressListener;
    }

    public function run(): int
    {
        if (! $this->terminal->isInteractive()) {
            fwrite(STDERR, "Error: vtail requires an interactive terminal\n");

            return 1;
        }

        if (! file_exists($this->file)) {
            touch($this->file);
        }

        $this->setupScreen();
        $this->setupHotkeys();
        $this->setupSignals();

        $this->terminal->setRawMode();
        $this->terminal->enterAlternateScreen();
        $this->terminal->hideCursor();

        $this->startTailProcess();

        try {
            $this->eventLoop();
        } finally {
            $this->cleanup();
        }

        return 0;
    }

    protected function setupScreen(): void
    {
        $this->terminal->initDimensions();
        $this->formatter = new LogFormatter($this->getContentWidth());
    }

    protected function setupHotkeys(): void
    {
        $this->listener
            ->on('v', fn () => $this->toggleVendorFrames())
            ->on('w', fn () => $this->toggleWrapping())
            ->on('t', fn () => $this->truncateFile())
            ->on(['q', KeyCodes::CTRL_C, KeyCodes::CTRL_D], fn () => $this->running = false)
            ->on([KeyCodes::UP, 'k'], fn () => $this->scrollUp())
            ->on([KeyCodes::DOWN, 'j'], fn () => $this->scrollDown())
            ->on(KeyCodes::PAGE_UP, fn () => $this->pageUp())
            ->on(KeyCodes::PAGE_DOWN, fn () => $this->pageDown())
            ->on('g', fn () => $this->scrollToTop())
            ->on('G', fn () => $this->scrollToBottom())
            ->on('f', fn () => $this->toggleFollow())
            ->on(KeyCodes::SPACE, fn () => $this->toggleFollow());
    }

    protected function setupSignals(): void
    {
        pcntl_async_signals(true);

        pcntl_signal(SIGWINCH, function () {
            $this->handleResize();
        });

        pcntl_signal(SIGINT, function () {
            $this->running = false;
        });

        pcntl_signal(SIGTERM, function () {
            $this->running = false;
        });
    }

    protected function startTailProcess(): void
    {
        $descriptors = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w'],  // stderr
        ];

        $this->tailProcess = proc_open(
            'tail -f -n '.$this->tailLines.' '.escapeshellarg($this->file),
            $descriptors,
            $pipes
        );

        if (! is_resource($this->tailProcess)) {
            throw new \RuntimeException('Failed to start tail process');
        }

        $this->tailPipes = $pipes;

        // Make stdout non-blocking
        stream_set_blocking($pipes[1], false);
    }

    protected function eventLoop(): void
    {
        while ($this->running) {
            if ($this->collectOutput()) {
                $this->dirty = true;
            }

            if ($this->dirty) {
                $this->processLines();
                $this->render();
                $this->dirty = false;
            }

            $this->waitForInput(self::FRAME_INTERVAL_US);
        }
    }

    protected function collectOutput(): bool
    {
        if (! $this->tailPipes) {
            return false;
        }

        $output = stream_get_contents($this->tailPipes[1]);

        if ($output) {
            $newLines = explode("\n", $output);

            foreach ($newLines as $line) {
                if ($line !== '') {
                    $this->lines[] = $line;
                }
            }

            // Trim old lines if we exceed the threshold
            if (count($this->lines) > $this->trimThreshold) {
                $this->trimOldLines();
            }

            return true;
        }

        return false;
    }

    /**
     * Trim old lines to stay within maxLines limit.
     * Adjusts scroll position to maintain view stability.
     */
    protected function trimOldLines(): void
    {
        $removeCount = count($this->lines) - $this->maxLines;

        if ($removeCount <= 0) {
            return;
        }

        // Trim raw lines
        $this->lines = array_slice($this->lines, $removeCount);

        // Trim formatted lines and get display line count removed
        $removedDisplayLines = 0;
        if ($this->lineCollection !== null) {
            $removedDisplayLines = $this->lineCollection->trimFromStart($removeCount);
        }

        // Adjust scroll position
        $this->scrollIndex = max(0, $this->scrollIndex - $removedDisplayLines);

        // Reset formatting index since we removed lines
        $this->lastFormattedIndex = max(0, $this->lastFormattedIndex - $removeCount);

        // Need full rebuild to re-index everything
        $this->needsRebuild = true;
    }

    protected function processLines(): void
    {
        $this->formatter->setContentWidth($this->getContentWidth());
        $this->formatter->setWrapLines($this->wrapLines);

        // Check if we need full rebuild (settings changed, trimmed, or first run)
        if ($this->needsFullRebuild()) {
            $this->formatter->reset();
            $this->lineCollection = $this->formatter->formatLines($this->lines);
            $this->lastFormattedIndex = count($this->lines);
        } elseif ($this->lastFormattedIndex < count($this->lines)) {
            // Incremental: only format new lines
            $newLines = $this->formatter->formatNewLines(
                $this->lines,
                $this->lastFormattedIndex
            );
            $this->lineCollection->appendLines($newLines);
            $this->lastFormattedIndex = count($this->lines);
        }

        $this->lineCollection->setContentWidth($this->getContentWidth());
        $this->lineCollection->setHideVendor($this->hideVendor);

        if ($this->following) {
            $this->scrollToBottom();
        }
    }

    /**
     * Check if a full rebuild is needed.
     */
    protected function needsFullRebuild(): bool
    {
        if ($this->needsRebuild || $this->lineCollection === null) {
            $this->needsRebuild = false;
            $this->lastContentWidth = $this->getContentWidth();
            $this->lastWrapLines = $this->wrapLines;

            return true;
        }

        $widthChanged = $this->lastContentWidth !== $this->getContentWidth();
        $wrapChanged = $this->lastWrapLines !== $this->wrapLines;

        if ($widthChanged || $wrapChanged) {
            $this->lastContentWidth = $this->getContentWidth();
            $this->lastWrapLines = $this->wrapLines;

            return true;
        }

        return false;
    }

    protected function render(): void
    {
        // Move cursor to home position
        echo "\e[H";

        // Top bar: status with preferences
        echo $this->renderStatusBar()."\r\n";

        // Content - directly echo visible lines
        // Use \r\n to explicitly end each line; prevents "pending wrap" behavior
        // in terminals like Ghostty where \n alone can cause display artifacts
        if ($this->lineCollection !== null) {
            $visible = $this->lineCollection->getDisplayLines($this->scrollIndex, $this->getContentHeight());
            $cols = $this->terminal->cols();
            foreach ($visible as $line) {
                // Lines are already formatted to content width by LogFormatter.
                // Only truncate if somehow longer than terminal (safety check).
                // Don't pad - the formatter handles width, and padding would
                // add spaces after the right border, pushing it off screen.
                if (AnsiAware::mb_strlen($line) > $cols) {
                    $line = AnsiAware::substr($line, 0, $cols);
                }
                echo "\e[2K".$line."\r\n";
            }

            // Clear remaining rows if content doesn't fill screen
            $remaining = $this->getContentHeight() - count($visible);
            for ($i = 0; $i < $remaining; $i++) {
                echo "\e[K\r\n";
            }
        } else {
            // No content yet, clear the content area
            for ($i = 0; $i < $this->getContentHeight(); $i++) {
                echo "\e[K\r\n";
            }
        }

        // Bottom bar
        $this->terminal->moveCursor($this->terminal->lines(), 1);
        echo $this->renderHotkeyBar();
    }

    protected function renderStatusBar(): string
    {
        $filename = basename($this->file);
        $lineCount = $this->lineCollection?->getDisplayLineCount() ?? 0;

        $wrap = $this->wrapLines ? 'WRAP: on' : 'WRAP: off';
        $vendor = $this->hideVendor ? 'VENDOR: hidden' : 'VENDOR: shown';
        $follow = $this->following ? 'FOLLOWING' : '';

        $parts = array_filter([$filename, "Lines: {$lineCount}", $vendor, $wrap, $follow]);
        $status = ' '.implode(' | ', $parts).' ';

        $padded = AnsiAware::pad($status, $this->terminal->cols());

        return "\e[7m".$padded."\e[27m";
    }

    protected function renderHotkeyBar(): string
    {
        $hotkeys = [
            'v' => $this->hideVendor ? 'show vendor' : 'hide vendor',
            'w' => $this->wrapLines ? 'disable wrapping' : ' enable wrapping',
            't' => 'truncate file',
            'f' => $this->following ? 'unfollow' : 'follow',
            'q' => 'quit',
        ];

        $parts = [];
        foreach ($hotkeys as $key => $label) {
            $parts[] = $key.' '.$label;
        }

        $bar = ' '.implode('  ', $parts);

        $padded = AnsiAware::pad($bar, $this->terminal->cols());

        return "\e[7m".$padded."\e[27m";
    }

    protected function waitForInput(int $timeoutUs): void
    {
        $read = [STDIN];
        $write = $except = null;

        $seconds = (int) floor($timeoutUs / 1000000);
        $microseconds = $timeoutUs % 1000000;

        if (@stream_select($read, $write, $except, $seconds, $microseconds) === 1) {
            $key = fread(STDIN, 16);
            if ($key !== false && $key !== '') {
                $this->listener->processKey($key);
            }
        }
    }

    protected function handleResize(): void
    {
        $this->terminal->initDimensions();
        $this->formatter->setContentWidth($this->getContentWidth());
        $this->clampScrollIndex();
        $this->dirty = true;
    }

    protected function clampScrollIndex(): void
    {
        $totalLines = $this->lineCollection?->getDisplayLineCount() ?? 0;
        $maxScroll = max(0, $totalLines - $this->getContentHeight());
        $this->scrollIndex = min($this->scrollIndex, $maxScroll);
    }

    protected function toggleVendorFrames(): void
    {
        $wasHiding = $this->hideVendor;
        $this->hideVendor = ! $this->hideVendor;

        // Adjust scroll position to keep same content visible
        if ($this->lineCollection !== null) {
            $this->scrollIndex = $this->lineCollection->getScrollIndexForVendorToggle(
                $wasHiding,
                $this->hideVendor,
                $this->scrollIndex
            );
            $this->lineCollection->setHideVendor($this->hideVendor);
        }

        $this->dirty = true;
    }

    protected function toggleWrapping(): void
    {
        $wasWrapping = $this->wrapLines;
        $this->wrapLines = ! $this->wrapLines;

        // Adjust scroll position to keep same content visible
        if ($this->lineCollection !== null) {
            $this->scrollIndex = $this->lineCollection->getScrollIndexForWrapToggle(
                $wasWrapping,
                $this->wrapLines,
                $this->scrollIndex
            );
        }

        $this->dirty = true;
    }

    protected function truncateFile(): void
    {
        file_put_contents($this->file, '');
        $this->lines = [];
        $this->lineCollection?->clear();
        $this->scrollIndex = 0;
        $this->lastFormattedIndex = 0;
        $this->needsRebuild = true;
        $this->dirty = true;
    }

    protected function toggleFollow(): void
    {
        $this->following = ! $this->following;

        if ($this->following) {
            $this->scrollToBottom();
        }
        $this->dirty = true;
    }

    protected function scrollUp(int $lines = 1): void
    {
        $this->following = false;
        $this->scrollIndex = max(0, $this->scrollIndex - $lines);
        $this->dirty = true;
    }

    protected function scrollDown(int $lines = 1): void
    {
        $totalLines = $this->lineCollection?->getDisplayLineCount() ?? 0;
        $maxScroll = max(0, $totalLines - $this->getContentHeight());
        $this->scrollIndex = min($maxScroll, $this->scrollIndex + $lines);

        if ($this->scrollIndex >= $maxScroll) {
            $this->following = true;
        }
        $this->dirty = true;
    }

    protected function pageUp(): void
    {
        $this->scrollUp($this->getContentHeight() - 1);
    }

    protected function pageDown(): void
    {
        $this->scrollDown($this->getContentHeight() - 1);
    }

    protected function scrollToTop(): void
    {
        $this->following = false;
        $this->scrollIndex = 0;
        $this->dirty = true;
    }

    protected function scrollToBottom(): void
    {
        $totalLines = $this->lineCollection?->getDisplayLineCount() ?? 0;
        $this->scrollIndex = max(0, $totalLines - $this->getContentHeight());
    }

    protected function getContentHeight(): int
    {
        return max(1, $this->terminal->lines() - 2);
    }

    protected function getContentWidth(): int
    {
        return $this->terminal->cols();
    }

    protected function cleanup(): void
    {
        if ($this->tailProcess) {
            proc_terminate($this->tailProcess);
            proc_close($this->tailProcess);
        }

        $this->terminal->showCursor();
        $this->terminal->exitAlternateScreen();
        $this->terminal->restoreTty();
    }
}
