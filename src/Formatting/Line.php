<?php

/**
 * @author Aaron Francis <aaron@tryhardstudios.com>
 *
 * @link https://aaronfrancis.com
 * @link https://x.com/aarondfrancis
 */

namespace SoloTerm\Vtail\Formatting;

class Line
{
    /**
     * @param  string  $content  Raw log line content
     * @param  array<string>  $formattedLines  Display-ready lines (may be multiple if wrapped)
     * @param  int  $originalIndex  Index in the raw lines array
     * @param  int  $fullWrapCount  Number of lines if wrapping enabled (for scroll calc when toggling)
     * @param  bool  $isStackFrame  Whether this is a stack trace frame
     * @param  bool  $isVendorFrame  Whether this is a vendor stack frame
     * @param  int|null  $vendorGroupId  ID for consecutive vendor frames (for collapsing)
     */
    public function __construct(
        public readonly string $content,
        public readonly array $formattedLines,
        public readonly int $originalIndex,
        public readonly int $fullWrapCount = 1,
        public readonly bool $isStackFrame = false,
        public readonly bool $isVendorFrame = false,
        public readonly ?int $vendorGroupId = null,
    ) {}

    /**
     * Get the number of display lines this Line produces.
     */
    public function wrapCount(): int
    {
        return count($this->formattedLines);
    }

    /**
     * Check if this line can be collapsed with other vendor frames.
     */
    public function isCollapsibleVendor(): bool
    {
        return $this->isVendorFrame && $this->vendorGroupId !== null;
    }

    /**
     * Get the formatted content as a single string.
     */
    public function getFormattedContent(): string
    {
        return implode("\n", $this->formattedLines);
    }
}
