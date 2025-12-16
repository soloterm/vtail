<?php

/**
 * @author Aaron Francis <aaron@tryhardstudios.com>
 *
 * @link https://aaronfrancis.com
 * @link https://x.com/aarondfrancis
 */

namespace SoloTerm\Vtail\Formatting;

/**
 * Parses and formats Laravel log lines with stack trace handling.
 *
 * Maintains state while processing lines to:
 * - Track consecutive vendor frames for collapsing
 * - Detect stack trace boundaries for bordered formatting
 * - Handle pre-wrapped continuation lines within traces
 *
 * State is reset via reset() when processing a new log file or after truncation.
 */
class LogFormatter
{
    protected int $contentWidth;

    protected bool $wrapLines = true;

    /**
     * Current vendor group ID for tracking consecutive vendor frames.
     */
    protected int $vendorGroupId = 0;

    /**
     * Whether we're currently in a vendor group.
     */
    protected bool $inVendorGroup = false;

    /**
     * Whether we're currently inside a stack trace.
     */
    protected bool $inStackTrace = false;

    public function __construct(int $contentWidth)
    {
        $this->contentWidth = $contentWidth;
    }

    /**
     * Enable or disable line wrapping.
     */
    public function setWrapLines(bool $wrapLines): void
    {
        $this->wrapLines = $wrapLines;
    }

    /**
     * Set the content width for formatting.
     */
    public function setContentWidth(int $width): void
    {
        $this->contentWidth = $width;
    }

    /**
     * Reset formatter state for a fresh log file.
     */
    public function reset(): void
    {
        $this->vendorGroupId = 0;
        $this->inVendorGroup = false;
        $this->inStackTrace = false;
    }

    /**
     * Format all lines into a LineCollection.
     *
     * @param  array<string>  $rawLines
     */
    public function formatLines(array $rawLines): LineCollection
    {
        $collection = new LineCollection;

        foreach ($rawLines as $index => $rawLine) {
            $line = $this->formatLine($rawLine, $index);

            if ($line !== null) {
                $collection->addLine($line);
            }
        }

        return $collection;
    }

    /**
     * Format only new lines starting from a given index.
     * Does not reset state, allowing incremental processing.
     *
     * @param  array<string>  $rawLines
     * @return array<Line>
     */
    public function formatNewLines(array $rawLines, int $startIndex): array
    {
        $newLines = [];

        for ($i = $startIndex; $i < count($rawLines); $i++) {
            $line = $this->formatLine($rawLines[$i], $i);

            if ($line !== null) {
                $newLines[] = $line;
            }
        }

        return $newLines;
    }

    /**
     * Format a single log line into a Line object.
     */
    public function formatLine(string $rawLine, int $index): ?Line
    {
        // Content line structure: " │ " + content + " │" = 5 chars for borders
        $traceContentWidth = $this->contentWidth - 5;

        // Footer: closing JSON exception object
        if (str_contains($rawLine, '"}') && trim(AnsiAware::plain($rawLine)) === '"}') {
            $this->endVendorGroup();
            $this->inStackTrace = false;

            return new Line(
                content: $rawLine,
                formattedLines: [AnsiAware::dim(' ╰'.str_repeat('═', $this->contentWidth - 3).'╯')],
                originalIndex: $index,
            );
        }

        // Exception header
        if (str_contains($rawLine, '{"exception":"[object] ')) {
            $this->endVendorGroup();
            [$formattedLines, $fullWrapCount] = $this->formatExceptionHeader($rawLine);

            return new Line(
                content: $rawLine,
                formattedLines: $formattedLines,
                originalIndex: $index,
                fullWrapCount: $fullWrapCount,
            );
        }

        // Stacktrace header
        if (str_contains($rawLine, '[stacktrace]')) {
            $this->endVendorGroup();
            $this->inStackTrace = true;

            return new Line(
                content: $rawLine,
                formattedLines: [AnsiAware::dim(' ╭─Trace'.str_repeat('─', $this->contentWidth - 9).'╮')],
                originalIndex: $index,
                isStackFrame: true,
            );
        }

        // Stack trace frame
        if (preg_match('/#[0-9]+ /', $rawLine)) {
            return $this->createStackFrameLine($rawLine, $index, $traceContentWidth);
        }

        // Stack trace continuation (inside trace but doesn't start with #)
        if ($this->inStackTrace) {
            return $this->createStackFrameContinuation($rawLine, $index, $traceContentWidth);
        }

        // Regular line - wrap or truncate
        $this->endVendorGroup();
        [$formattedLines, $fullWrapCount] = $this->wrapWithCount($rawLine, $this->contentWidth);

        return new Line(
            content: $rawLine,
            formattedLines: $formattedLines,
            originalIndex: $index,
            fullWrapCount: $fullWrapCount,
        );
    }

    /**
     * Create a Line object for a stack frame.
     */
    protected function createStackFrameLine(string $rawLine, int $index, int $traceContentWidth): Line
    {
        // Pad single-digit frame numbers
        $line = preg_replace('/^(\e\[0m)?#(\d)(?!\d)/', '$1#0$2', $rawLine);

        $isVendor = $this->isVendorFrame($line);
        $vendorGroupId = null;

        if ($isVendor) {
            if (! $this->inVendorGroup) {
                $this->vendorGroupId++;
                $this->inVendorGroup = true;
            }
            $vendorGroupId = $this->vendorGroupId;
        } else {
            $this->endVendorGroup();
        }

        // Format the line fully (both vendor and non-vendor)
        $line = $this->highlightFileOnly($line);
        [$wrappedLines, $fullWrapCount] = $this->wrapWithCount($line, $traceContentWidth, 4);

        $formattedLines = array_map(
            fn ($l) => AnsiAware::dim(' │ ').AnsiAware::pad($l, $traceContentWidth).AnsiAware::dim(' │'),
            $wrappedLines
        );

        return new Line(
            content: $rawLine,
            formattedLines: $formattedLines,
            originalIndex: $index,
            fullWrapCount: $fullWrapCount,
            isStackFrame: true,
            isVendorFrame: $isVendor,
            vendorGroupId: $vendorGroupId,
        );
    }

    /**
     * Create a Line object for a stack frame continuation (pre-wrapped in log file).
     */
    protected function createStackFrameContinuation(string $rawLine, int $index, int $traceContentWidth): Line
    {
        // Inherit vendor status from current state (don't break the group)
        $vendorGroupId = $this->inVendorGroup ? $this->vendorGroupId : null;

        [$wrappedLines, $fullWrapCount] = $this->wrapWithCount($rawLine, $traceContentWidth, 4);

        $formattedLines = array_map(
            fn ($l) => AnsiAware::dim(' │ ').AnsiAware::pad($l, $traceContentWidth).AnsiAware::dim(' │'),
            $wrappedLines
        );

        return new Line(
            content: $rawLine,
            formattedLines: $formattedLines,
            originalIndex: $index,
            fullWrapCount: $fullWrapCount,
            isStackFrame: true,
            isVendorFrame: $this->inVendorGroup,
            vendorGroupId: $vendorGroupId,
        );
    }

    /**
     * End the current vendor group.
     */
    protected function endVendorGroup(): void
    {
        $this->inVendorGroup = false;
    }

    /**
     * Format an exception header line.
     *
     * @return array{0: array<string>, 1: int} [formattedLines, fullWrapCount]
     */
    protected function formatExceptionHeader(string $line): array
    {
        $parts = explode('{"exception":"[object] ', $line);

        [$messageLines, $messageWrapCount] = $this->wrapWithCount($parts[0], $this->contentWidth);
        [$exceptionLines, $exceptionWrapCount] = $this->wrapWithCount($parts[1] ?? '', $this->contentWidth - 1);

        // Indent exception lines
        $exception = array_map(fn ($l) => ' '.$l, $exceptionLines);

        $formattedLines = array_merge($messageLines, $exception);
        $fullWrapCount = $messageWrapCount + $exceptionWrapCount;

        return [$formattedLines, $fullWrapCount];
    }

    /**
     * Highlight the file path in a stack frame, dimming frame number and method.
     */
    protected function highlightFileOnly(string $line): string
    {
        // Pattern handles lines with or without \e[0m prefix
        $pattern = '/^(\e\[0m)?(#\d+)(.*?)(:.*)?$/';
        $replacement = "\033[2m$2\033[0m$3\033[2m$4\033[0m";

        return preg_replace($pattern, $replacement, $line);
    }

    /**
     * Check if a line represents a vendor frame.
     *
     * A frame is considered vendor if:
     * - Path contains /vendor/ (except BoundMethod.php calling App\ code)
     * - Frame is {main} (execution root)
     */
    public function isVendorFrame(string $line): bool
    {
        $plain = AnsiAware::plain($line);

        return (str_contains($plain, '/vendor/') && ! preg_match("/BoundMethod\.php\([0-9]+\): App/", $plain))
            || str_ends_with($plain, '{main}');
    }

    /**
     * Wrap a line and return both the result and full wrap count.
     * Used for scroll position preservation when toggling wrap mode.
     *
     * @return array{0: array<string>, 1: int} [formattedLines, fullWrapCount]
     */
    protected function wrapWithCount(string $line, int $width, int $continuationIndent = 0): array
    {
        if ($width <= 0) {
            return [[$line], 1];
        }

        $wrapped = $this->wrapLine($line, $width, $continuationIndent);
        $fullWrapCount = count($wrapped);

        if (! $this->wrapLines && $fullWrapCount > 1) {
            // Truncate: keep first line, add indicator
            $indicator = AnsiAware::dim(' ...');
            $indicatorLen = AnsiAware::mb_strlen($indicator);

            $truncated = explode("\n", AnsiAware::wordwrap($wrapped[0], $width - $indicatorLen, "\n", true));

            return [[$truncated[0].$indicator], $fullWrapCount];
        }

        return [$wrapped, $fullWrapCount];
    }

    /**
     * Wrap a line to the given width.
     *
     * @return array<string>
     */
    public function wrapLine(string $line, int $width, int $continuationIndent = 0): array
    {
        $contWidth = $continuationIndent > 0 ? $width - $continuationIndent : $width;

        $firstWrap = explode("\n", AnsiAware::wordwrap($line, $width, "\n", true));
        $result = [$firstWrap[0]];

        if (count($firstWrap) > 1) {
            $remainder = implode('', array_slice($firstWrap, 1));
            $contLines = explode("\n", AnsiAware::wordwrap($remainder, $contWidth, "\n", true));

            $indent = str_repeat(' ', $continuationIndent);
            foreach ($contLines as $contLine) {
                if (trim($contLine) !== '') {
                    $result[] = $indent.$contLine;
                }
            }
        }

        // If wrapping is disabled, truncate to first line with indicator
        if (! $this->wrapLines && count($result) > 1) {
            $indicator = AnsiAware::dim(' ...');
            $indicatorLen = AnsiAware::mb_strlen($indicator);

            $truncated = explode("\n", AnsiAware::wordwrap($result[0], $width - $indicatorLen, "\n", true));

            return [$truncated[0].$indicator];
        }

        return $result;
    }
}
