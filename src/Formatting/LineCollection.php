<?php

/**
 * @author Aaron Francis <aaron@tryhardstudios.com>
 *
 * @link https://aaronfrancis.com
 * @link https://x.com/aarondfrancis
 */

namespace SoloTerm\Vtail\Formatting;

class LineCollection
{
    /**
     * @var Line[]
     */
    protected array $lines = [];

    /**
     * @var string[] Flattened display lines after processing
     */
    protected array $displayLines = [];

    protected bool $hideVendor = false;

    protected bool $dirty = true;

    protected int $contentWidth = 80;

    /**
     * Add a Line to the collection.
     */
    public function addLine(Line $line): void
    {
        $this->lines[] = $line;
        $this->dirty = true;
    }

    /**
     * Set all lines at once.
     *
     * @param  Line[]  $lines
     */
    public function setLines(array $lines): void
    {
        $this->lines = $lines;
        $this->dirty = true;
    }

    /**
     * Clear all lines.
     */
    public function clear(): void
    {
        $this->lines = [];
        $this->displayLines = [];
        $this->dirty = true;
    }

    /**
     * Append multiple lines at once.
     *
     * @param  Line[]  $lines
     */
    public function appendLines(array $lines): void
    {
        foreach ($lines as $line) {
            $this->lines[] = $line;
        }
        $this->dirty = true;
    }

    /**
     * Remove lines from the start of the collection.
     * Returns the number of display lines that were removed (for scroll adjustment).
     */
    public function trimFromStart(int $count): int
    {
        if ($count <= 0 || empty($this->lines)) {
            return 0;
        }

        $count = min($count, count($this->lines));

        // Calculate display lines being removed
        $removedDisplayLines = 0;
        for ($i = 0; $i < $count; $i++) {
            $removedDisplayLines += $this->lines[$i]->wrapCount();
        }

        $this->lines = array_slice($this->lines, $count);
        $this->dirty = true;

        return $removedDisplayLines;
    }

    /**
     * Set whether to hide vendor frames.
     */
    public function setHideVendor(bool $hide): void
    {
        if ($this->hideVendor !== $hide) {
            $this->hideVendor = $hide;
            $this->dirty = true;
        }
    }

    /**
     * Set the content width for formatting collapsed markers.
     */
    public function setContentWidth(int $width): void
    {
        if ($this->contentWidth !== $width) {
            $this->contentWidth = $width;
            $this->dirty = true;
        }
    }

    /**
     * Get the total count of display lines.
     */
    public function getDisplayLineCount(): int
    {
        $this->processIfDirty();

        return count($this->displayLines);
    }

    /**
     * Get a slice of display lines for rendering.
     *
     * @return string[]
     */
    public function getDisplayLines(int $start, int $count): array
    {
        $this->processIfDirty();

        return array_slice($this->displayLines, $start, $count);
    }

    /**
     * Get all display lines.
     *
     * @return string[]
     */
    public function getAllDisplayLines(): array
    {
        $this->processIfDirty();

        return $this->displayLines;
    }

    /**
     * Calculate scroll index adjustment when toggling vendor visibility.
     *
     * @param  bool  $wasHiding  Previous hide vendor state
     * @param  bool  $nowHiding  New hide vendor state
     * @param  int  $currentIndex  Current scroll index
     * @return int  New scroll index
     */
    public function getScrollIndexForVendorToggle(bool $wasHiding, bool $nowHiding, int $currentIndex): int
    {
        if ($wasHiding === $nowHiding || $currentIndex === 0) {
            return $currentIndex;
        }

        if (!$wasHiding && $nowHiding) {
            // Hiding vendor: vendor groups collapse to single lines
            return $this->calculateScrollForHideVendor($currentIndex);
        } else {
            // Showing vendor: collapsed markers expand to full vendor frames
            return $this->calculateScrollForShowVendor($currentIndex);
        }
    }

    /**
     * Calculate scroll position when hiding vendor (frames collapse to markers).
     */
    protected function calculateScrollForHideVendor(int $currentIndex): int
    {
        $displayLinesSeen = 0;
        $adjustment = 0;
        $vendorGroupsSeen = [];

        foreach ($this->lines as $line) {
            if ($line->isVendorFrame && $line->vendorGroupId !== null) {
                $wrapCount = $line->wrapCount();

                if (!isset($vendorGroupsSeen[$line->vendorGroupId])) {
                    // First frame in group: will become 1 collapsed line
                    $vendorGroupsSeen[$line->vendorGroupId] = true;

                    if ($displayLinesSeen + $wrapCount >= $currentIndex) {
                        // Scroll is within this vendor group
                        break;
                    }

                    // This vendor frame's lines will be replaced by 1 collapsed line
                    $adjustment += $wrapCount - 1;
                    $displayLinesSeen += $wrapCount;
                } else {
                    // Additional frame in same group: will be completely removed
                    if ($displayLinesSeen + $wrapCount >= $currentIndex) {
                        // Scroll is within this vendor frame that will be removed
                        // Adjust to end of the collapsed marker for this group
                        break;
                    }

                    $adjustment += $wrapCount;
                    $displayLinesSeen += $wrapCount;
                }
            } else {
                $wrapCount = $line->wrapCount();

                if ($displayLinesSeen + $wrapCount >= $currentIndex) {
                    break;
                }

                $displayLinesSeen += $wrapCount;
            }
        }

        return max(0, $currentIndex - $adjustment);
    }

    /**
     * Calculate scroll position when showing vendor (markers expand to full frames).
     */
    protected function calculateScrollForShowVendor(int $currentIndex): int
    {
        $displayLinesSeen = 0;
        $linesToAdd = 0;
        $vendorGroupsSeen = [];
        $vendorGroupLineCounts = [];

        // First pass: count total lines per vendor group
        foreach ($this->lines as $line) {
            if ($line->isVendorFrame && $line->vendorGroupId !== null) {
                if (!isset($vendorGroupLineCounts[$line->vendorGroupId])) {
                    $vendorGroupLineCounts[$line->vendorGroupId] = 0;
                }
                $vendorGroupLineCounts[$line->vendorGroupId] += $line->wrapCount();
            }
        }

        // Second pass: calculate scroll adjustment
        foreach ($this->lines as $line) {
            if ($line->isVendorFrame && $line->vendorGroupId !== null) {
                if (!isset($vendorGroupsSeen[$line->vendorGroupId])) {
                    $vendorGroupsSeen[$line->vendorGroupId] = true;

                    // Currently showing 1 collapsed line, will expand to full group
                    if ($displayLinesSeen + 1 >= $currentIndex) {
                        break;
                    }

                    $fullGroupLines = $vendorGroupLineCounts[$line->vendorGroupId];
                    $linesToAdd += $fullGroupLines - 1;
                    $displayLinesSeen += 1; // Collapsed marker = 1 line
                }
                // Skip additional frames in same group (they're collapsed)
            } else {
                $wrapCount = $line->wrapCount();

                if ($displayLinesSeen + $wrapCount >= $currentIndex) {
                    break;
                }

                $displayLinesSeen += $wrapCount;
            }
        }

        return $currentIndex + $linesToAdd;
    }

    /**
     * Calculate scroll index adjustment when toggling line wrapping.
     *
     * @param  bool  $wasWrapping  Previous wrap state
     * @param  bool  $nowWrapping  New wrap state
     * @param  int  $currentIndex  Current scroll index
     * @return int  New scroll index
     */
    public function getScrollIndexForWrapToggle(bool $wasWrapping, bool $nowWrapping, int $currentIndex): int
    {
        if ($wasWrapping === $nowWrapping || $currentIndex === 0) {
            return $currentIndex;
        }

        if ($wasWrapping && !$nowWrapping) {
            // Disabling wrap: multiple display lines collapse to single lines
            return $this->calculateScrollForDisableWrap($currentIndex);
        } else {
            // Enabling wrap: single lines expand to multiple display lines
            return $this->calculateScrollForEnableWrap($currentIndex);
        }
    }

    /**
     * Calculate scroll position when disabling wrap (lines collapse).
     * Count continuation lines above scroll that will be removed.
     */
    protected function calculateScrollForDisableWrap(int $currentIndex): int
    {
        $displayLinesSeen = 0;
        $continuationLinesAbove = 0;

        foreach ($this->lines as $line) {
            // Skip vendor frames if they're hidden
            if ($this->hideVendor && $line->isVendorFrame && $line->vendorGroupId !== null) {
                // Collapsed vendor frame = 1 display line, no continuation
                $displayLinesSeen++;
                if ($displayLinesSeen >= $currentIndex) {
                    break;
                }
                continue;
            }

            $wrapCount = $line->wrapCount();

            // Check if we've reached the scroll position
            if ($displayLinesSeen + $wrapCount >= $currentIndex) {
                // Partial: count continuation lines up to scroll position
                $linesInThisEntry = $currentIndex - $displayLinesSeen;
                $continuationLinesAbove += max(0, $linesInThisEntry - 1);
                break;
            }

            // Full line is above scroll position
            $displayLinesSeen += $wrapCount;
            $continuationLinesAbove += max(0, $wrapCount - 1);
        }

        return max(0, $currentIndex - $continuationLinesAbove);
    }

    /**
     * Calculate scroll position when enabling wrap (lines expand).
     * Sum up lines that will be added above scroll position.
     */
    protected function calculateScrollForEnableWrap(int $currentIndex): int
    {
        $displayLinesSeen = 0;
        $linesToAdd = 0;

        foreach ($this->lines as $line) {
            // Skip vendor frames if they're hidden
            if ($this->hideVendor && $line->isVendorFrame && $line->vendorGroupId !== null) {
                // Collapsed vendor frame = 1 display line
                $displayLinesSeen++;
                if ($displayLinesSeen >= $currentIndex) {
                    break;
                }
                continue;
            }

            // Currently showing 1 line (truncated), will expand to fullWrapCount
            $currentDisplayLines = $line->wrapCount(); // Should be 1 when truncated
            $fullWrapCount = $line->fullWrapCount;

            // Check if we've reached the scroll position
            if ($displayLinesSeen + $currentDisplayLines >= $currentIndex) {
                // We're at or past the scroll position
                break;
            }

            // Full line is above scroll position - count extra lines it will become
            $displayLinesSeen += $currentDisplayLines;
            $linesToAdd += max(0, $fullWrapCount - $currentDisplayLines);
        }

        return $currentIndex + $linesToAdd;
    }

    /**
     * Process lines if the collection is dirty.
     */
    protected function processIfDirty(): void
    {
        if ($this->dirty) {
            $this->displayLines = $this->buildDisplayLines($this->hideVendor);
            $this->dirty = false;
        }
    }

    /**
     * Build the display lines array based on current settings.
     *
     * @return string[]
     */
    protected function buildDisplayLines(bool $hideVendor): array
    {
        $display = [];
        $vendorGroupsSeen = [];
        $vendorGroupCounts = [];

        // First pass: count frames per vendor group
        if ($hideVendor) {
            foreach ($this->lines as $line) {
                if ($line->isVendorFrame && $line->vendorGroupId !== null) {
                    if (!isset($vendorGroupCounts[$line->vendorGroupId])) {
                        $vendorGroupCounts[$line->vendorGroupId] = 0;
                    }
                    $vendorGroupCounts[$line->vendorGroupId]++;
                }
            }
        }

        foreach ($this->lines as $line) {
            if ($hideVendor && $line->isVendorFrame && $line->vendorGroupId !== null) {
                // Collapse vendor frames: only show one line per group
                if (!isset($vendorGroupsSeen[$line->vendorGroupId])) {
                    $vendorGroupsSeen[$line->vendorGroupId] = true;
                    // Create collapsed marker with count
                    $count = $vendorGroupCounts[$line->vendorGroupId];
                    $display[] = $this->createCollapsedMarker($count);
                }
                // Skip additional lines in same vendor group
            } else {
                // Add all formatted lines
                foreach ($line->formattedLines as $formattedLine) {
                    $display[] = $formattedLine;
                }
            }
        }

        return $display;
    }

    /**
     * Create a collapsed vendor frames marker.
     */
    protected function createCollapsedMarker(int $count): string
    {
        // Content line structure: " │ " + content + " │" = 5 chars for borders
        $traceContentWidth = $this->contentWidth - 5;
        $marker = "#… ({$count} vendor frames)";

        return $this->dim(' │ ').AnsiAware::pad($marker, $traceContentWidth).$this->dim(' │');
    }

    /**
     * Apply dim styling to text.
     */
    protected function dim(string $text): string
    {
        return "\e[2m{$text}\e[22m";
    }

    /**
     * Get the raw Line objects.
     *
     * @return Line[]
     */
    public function getLines(): array
    {
        return $this->lines;
    }

    /**
     * Get the count of raw lines.
     */
    public function count(): int
    {
        return count($this->lines);
    }
}
