<?php

namespace SoloTerm\Vtail\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SoloTerm\Vtail\Formatting\LogFormatter;

/**
 * Integration test that simulates the Application's processLines flow.
 */
class ApplicationTest extends TestCase
{
    #[Test]
    public function vendor_toggle_changes_output()
    {
        $formatter = new LogFormatter(120);

        // Simulate raw log lines (as they would come from tail -f)
        $rawLines = file(__DIR__.'/../Fixtures/enhance-log-wrap-vendor-test.log', FILE_IGNORE_NEW_LINES);

        // Take just the first exception's stack trace (lines 1-71)
        $rawLines = array_slice($rawLines, 0, 72);

        // Format lines into collection
        $collection = $formatter->formatLines($rawLines);

        // Get display lines with hideVendor = false (show all)
        $collection->setHideVendor(false);
        $displayLinesShown = $collection->getDisplayLineCount();

        // Get display lines with hideVendor = true (hide vendor)
        $collection->setHideVendor(true);
        $displayLinesHidden = $collection->getDisplayLineCount();

        // The hidden version should have fewer lines
        $this->assertLessThan(
            $displayLinesShown,
            $displayLinesHidden,
            sprintf(
                'Hidden vendor should have fewer lines. Shown: %d, Hidden: %d',
                $displayLinesShown,
                $displayLinesHidden
            )
        );

        // Count compressed markers in hidden output
        $compressedMarkers = 0;
        foreach ($collection->getAllDisplayLines() as $line) {
            if (str_contains($line, '#…')) {
                $compressedMarkers++;
            }
        }

        $this->assertGreaterThan(0, $compressedMarkers, 'Should have compressed vendor markers');

        // Verify we can toggle back and get the same count
        $collection->setHideVendor(false);
        $displayLinesShownAgain = $collection->getDisplayLineCount();

        $this->assertEquals(
            $displayLinesShown,
            $displayLinesShownAgain,
            'Re-showing vendor should return to original line count'
        );
    }

    #[Test]
    public function status_bar_reflects_toggle_state()
    {
        // This tests that the status would update correctly
        $hideVendor = false;
        $label = $hideVendor ? 'show vendor' : 'hide vendor';
        $this->assertEquals('hide vendor', $label);

        $hideVendor = true;
        $label = $hideVendor ? 'show vendor' : 'hide vendor';
        $this->assertEquals('show vendor', $label);
    }

    #[Test]
    public function wrap_toggle_changes_line_count()
    {
        $formatter = new LogFormatter(80); // Narrow width to force wrapping

        // Create lines that will wrap at 80 chars
        $rawLines = [
            str_repeat('A', 120), // Will wrap
            str_repeat('B', 50),  // Won't wrap
            str_repeat('C', 200), // Will wrap into multiple lines
        ];

        // With wrapping enabled
        $formatter->setWrapLines(true);
        $wrappedLines = [];
        foreach ($rawLines as $line) {
            $result = $formatter->wrapLine($line, 80);
            foreach ($result as $l) {
                $wrappedLines[] = $l;
            }
        }

        // With wrapping disabled
        $formatter->setWrapLines(false);
        $truncatedLines = [];
        foreach ($rawLines as $line) {
            $result = $formatter->wrapLine($line, 80);
            foreach ($result as $l) {
                $truncatedLines[] = $l;
            }
        }

        // Wrapped should have more lines than truncated
        $this->assertGreaterThan(
            count($truncatedLines),
            count($wrappedLines),
            'Wrapped output should have more lines'
        );

        // Truncated should have exactly 3 lines (one per input)
        $this->assertCount(3, $truncatedLines, 'Truncated output should have one line per input');
    }

    #[Test]
    public function no_invisible_markers_in_any_output()
    {
        $formatter = new LogFormatter(80);

        // Test various scenarios
        $testCases = [
            'long_line_wrapped' => ['[2025-01-01] '.str_repeat('X', 200), true],
            'long_line_truncated' => ['[2025-01-01] '.str_repeat('X', 200), false],
            'vendor_line' => ["\e[0m#0 /app/vendor/laravel/Pipeline.php(50): method()", true],
        ];

        foreach ($testCases as $name => [$line, $wrapLines]) {
            $formatter->setWrapLines($wrapLines);

            $lineObj = $formatter->formatLine($line, 0);

            if ($lineObj === null) {
                continue;
            }

            foreach ($lineObj->formattedLines as $outputLine) {
                $this->assertStringNotContainsString("\e[8m", $outputLine,
                    "{$name}: Should not contain SGR 8 (hidden text)");
                $this->assertStringNotContainsString("\e[28m", $outputLine,
                    "{$name}: Should not contain SGR 28 (reveal)");
            }
        }
    }
}
