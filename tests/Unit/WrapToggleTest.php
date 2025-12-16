<?php

namespace SoloTerm\Vtail\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SoloTerm\Vtail\Formatting\LogFormatter;

class WrapToggleTest extends TestCase
{
    protected LogFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new LogFormatter(80);
    }

    #[Test]
    public function wrap_line_produces_multiple_lines_for_long_content()
    {
        $this->formatter->setWrapLines(true);

        // A line that will wrap at 80 chars
        $longLine = '[2025-01-01] '.str_repeat('x', 150);
        $lineObj = $this->formatter->formatLine($longLine, 0);

        // Should have wrapped
        $this->assertNotNull($lineObj);
        $this->assertGreaterThan(1, count($lineObj->formattedLines), 'Line should wrap');
    }

    #[Test]
    public function wrap_line_truncates_when_wrapping_disabled()
    {
        $this->formatter->setWrapLines(false);

        // A line that would wrap at 80 chars
        $longLine = '[2025-01-01] '.str_repeat('x', 200);
        $lineObj = $this->formatter->formatLine($longLine, 0);

        // Should be truncated to 1 line
        $this->assertNotNull($lineObj);
        $this->assertCount(1, $lineObj->formattedLines, 'Should return single line when wrapping disabled');
        $this->assertStringContainsString(' ...', $lineObj->formattedLines[0], 'Should contain ellipsis indicator');
    }

    #[Test]
    public function wrap_mode_can_be_toggled()
    {
        $longLine = str_repeat('x', 200);

        // Start with wrapping enabled
        $this->formatter->setWrapLines(true);
        $wrapped = $this->formatter->wrapLine($longLine, 80);
        $this->assertGreaterThan(1, count($wrapped), 'Should wrap when enabled');

        // Disable wrapping
        $this->formatter->setWrapLines(false);
        $truncated = $this->formatter->wrapLine($longLine, 80);
        $this->assertCount(1, $truncated, 'Should truncate when disabled');

        // Re-enable wrapping
        $this->formatter->setWrapLines(true);
        $rewrapped = $this->formatter->wrapLine($longLine, 80);
        $this->assertGreaterThan(1, count($rewrapped), 'Should wrap again when re-enabled');
    }

    #[Test]
    public function short_lines_are_unchanged_regardless_of_wrap_mode()
    {
        $shortLine = 'Short line';

        $this->formatter->setWrapLines(true);
        $resultWrapped = $this->formatter->wrapLine($shortLine, 80);

        $this->formatter->setWrapLines(false);
        $resultTruncated = $this->formatter->wrapLine($shortLine, 80);

        $this->assertCount(1, $resultWrapped);
        $this->assertCount(1, $resultTruncated);
        $this->assertEquals($shortLine, $resultWrapped[0]);
        $this->assertEquals($shortLine, $resultTruncated[0]);
    }

    #[Test]
    public function no_invisible_markers_in_wrapped_output()
    {
        // When wrapping is disabled, we no longer use invisible markers for count
        $this->formatter->setWrapLines(false);

        $longLine = str_repeat('x', 200);
        $result = $this->formatter->wrapLine($longLine, 80);

        // Should NOT contain SGR 8 (hidden text) codes
        $this->assertStringNotContainsString("\e[8m", $result[0],
            'Should not contain SGR 8 (hidden text) escape sequence');
        $this->assertStringNotContainsString("\e[28m", $result[0],
            'Should not contain SGR 28 (reveal) escape sequence');
    }
}
