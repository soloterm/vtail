<?php

namespace SoloTerm\Vtail\Tests\Integration;

use SoloTerm\Screen\Screen;
use SoloTerm\Vtail\Application;
use SoloTerm\Vtail\Formatting\AnsiAware;
use SoloTerm\Vtail\Formatting\LogFormatter;

/**
 * A testable version of Application that renders to a virtual Screen
 * instead of echoing directly to the terminal.
 */
class TestableApplication extends Application
{
    protected Screen $virtualScreen;

    protected int $virtualWidth = 120;

    protected int $virtualHeight = 40;

    protected array $capturedKeys = [];

    public function __construct(
        string $file = '/tmp/test.log',
        bool $hideVendor = false,
        bool $wrapLines = true
    ) {
        $this->file = $file;
        $this->hideVendor = $hideVendor;
        $this->wrapLines = $wrapLines;

        $this->terminal = new MockTerminal($this->virtualWidth, $this->virtualHeight);
        $this->listener = new \SoloTerm\Vtail\Input\KeyPressListener;

        $this->setupScreen();
        $this->setupHotkeys();

        $this->virtualScreen = new Screen($this->virtualWidth, $this->virtualHeight);
    }

    public function setDimensions(int $width, int $height): self
    {
        $this->virtualWidth = $width;
        $this->virtualHeight = $height;
        $this->terminal = new MockTerminal($width, $height);
        $this->virtualScreen = new Screen($width, $height);
        $this->setupScreen();

        return $this;
    }

    /**
     * @param  array<string>  $lines
     */
    public function addLines(array $lines): self
    {
        foreach ($lines as $line) {
            $this->lines[] = $line;
        }
        $this->dirty = true;

        return $this;
    }

    public function addLine(string $line): self
    {
        return $this->addLines([$line]);
    }

    public function loadFixture(string $path): self
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES);

        return $this->addLines($lines);
    }

    public function pressKey(string $key): self
    {
        $this->capturedKeys[] = $key;
        $this->listener->processKey($key);
        $this->dirty = true;

        return $this;
    }

    public function renderFrame(): string
    {
        $this->processLines();

        return $this->captureRender();
    }

    protected function captureRender(): string
    {
        $this->virtualScreen = new Screen($this->virtualWidth, $this->virtualHeight);

        $output = '';

        // Status bar
        $filename = basename($this->file);
        $lineCount = $this->lineCollection?->getDisplayLineCount() ?? 0;
        $wrap = $this->wrapLines ? 'WRAP: on' : 'WRAP: off';
        $vendor = $this->hideVendor ? 'VENDOR: hidden' : 'VENDOR: shown';
        $follow = $this->following ? 'FOLLOWING' : '';

        $parts = array_filter([$filename, "Lines: {$lineCount}", $vendor, $wrap, $follow]);
        $statusText = ' '.implode(' | ', $parts).' ';
        $output .= "\e[7m".AnsiAware::pad($statusText, $this->virtualWidth)."\e[27m\n";

        // Content area
        $contentHeight = $this->getContentHeight();
        $visibleLines = $this->lineCollection?->getDisplayLines($this->scrollIndex, $contentHeight) ?? [];

        for ($i = 0; $i < $contentHeight; $i++) {
            $line = $visibleLines[$i] ?? '';
            $output .= AnsiAware::pad($line, $this->virtualWidth)."\n";
        }

        // Hotkey bar
        $output .= $this->renderHotkeyBar();

        $this->virtualScreen->write($output);

        return $this->virtualScreen->output();
    }

    /**
     * @return array<string>
     */
    public function getFormattedLines(): array
    {
        return $this->lineCollection?->getAllDisplayLines() ?? [];
    }

    /**
     * @return array<string>
     */
    public function getVisibleLines(): array
    {
        $contentHeight = $this->getContentHeight();

        return $this->lineCollection?->getDisplayLines($this->scrollIndex, $contentHeight) ?? [];
    }

    public function getPlainOutput(): string
    {
        $output = $this->renderFrame();

        return AnsiAware::plain($output);
    }

    /**
     * @return array<string>
     */
    public function getPlainRows(): array
    {
        $plain = $this->getPlainOutput();

        return explode("\n", $plain);
    }

    public function dump(): array
    {
        return [
            'dimensions' => "{$this->virtualWidth}x{$this->virtualHeight}",
            'contentHeight' => $this->getContentHeight(),
            'totalLines' => count($this->lines),
            'formattedLines' => $this->lineCollection?->getDisplayLineCount() ?? 0,
            'scrollIndex' => $this->scrollIndex,
            'following' => $this->following,
            'hideVendor' => $this->hideVendor,
            'wrapLines' => $this->wrapLines,
            'visibleLines' => $this->getVisibleLines(),
        ];
    }

    public function debugFormattedLines(): void
    {
        $formattedLines = $this->getFormattedLines();
        echo "\n=== Formatted Lines (".count($formattedLines)." total) ===\n";
        foreach ($formattedLines as $i => $line) {
            $plain = AnsiAware::plain($line);
            $indicator = ($i >= $this->scrollIndex && $i < $this->scrollIndex + $this->getContentHeight())
                ? '> '
                : '  ';
            printf("%s%3d: %s\n", $indicator, $i, $plain);
        }
        echo "=== End ===\n";
    }

    public function debugFrame(): void
    {
        $output = $this->renderFrame();
        echo "\n=== Rendered Frame ===\n";
        $visible = str_replace("\e", '\\e', $output);
        echo $visible;
        echo "\n=== End ===\n";
    }

    public function debugPlain(): void
    {
        echo "\n=== Plain Text Output ===\n";
        echo $this->getPlainOutput();
        echo "\n=== End ===\n";
    }

    public function getScrollIndex(): int
    {
        return $this->scrollIndex;
    }

    public function isFollowing(): bool
    {
        return $this->following;
    }

    public function isHidingVendor(): bool
    {
        return $this->hideVendor;
    }

    public function isWrapping(): bool
    {
        return $this->wrapLines;
    }

    public function getFormatter(): LogFormatter
    {
        return $this->formatter;
    }
}

/**
 * Mock terminal that returns fixed dimensions.
 */
class MockTerminal extends \SoloTerm\Vtail\Terminal\Terminal
{
    public function __construct(
        protected int $mockCols,
        protected int $mockLines
    ) {
        // Don't call parent constructor - we don't need real terminal
    }

    public function cols(): int
    {
        return $this->mockCols;
    }

    public function lines(): int
    {
        return $this->mockLines;
    }

    public function initDimensions(): void
    {
        // No-op for mock
    }

    public function isInteractive(): bool
    {
        return true;
    }

    public function setRawMode(): void {}

    public function restoreTty(): void {}

    public function enterAlternateScreen(): void {}

    public function exitAlternateScreen(): void {}

    public function hideCursor(): void {}

    public function showCursor(): void {}
}
