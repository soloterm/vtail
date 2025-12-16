<?php

namespace SoloTerm\Vtail\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SoloTerm\Vtail\Formatting\AnsiAware;
use SoloTerm\Vtail\Formatting\LogFormatter;

/**
 * Tests for stack trace continuation line handling.
 *
 * When log files contain pre-wrapped stack frames (logger split long lines),
 * continuation lines that don't start with # should still:
 * - Get borders
 * - Inherit vendor group status
 * - Be collapsed with their vendor group
 */
class StackTraceContinuationTest extends TestCase
{
    #[Test]
    public function continuation_lines_get_borders()
    {
        $formatter = new LogFormatter(100);

        // Simulate a log with pre-wrapped stack frame
        $lines = [
            '[stacktrace]',
            '#0 /path/to/vendor/laravel/framework/src/Illuminate/Routing/Middleware/Sub',
            'stituteBindings.php(50): Illuminate\Routing\Middleware\SubstituteBindings->handle()',
            '"}',
        ];

        $collection = $formatter->formatLines($lines);
        $lineObjects = $collection->getLines();

        // Line 2 (continuation) should have borders
        $continuationLine = $lineObjects[2];
        $formatted = $continuationLine->formattedLines[0];
        $plain = AnsiAware::plain($formatted);

        $this->assertStringStartsWith(' │', $plain, 'Continuation should have left border');
        $this->assertStringEndsWith('│', $plain, 'Continuation should have right border');
    }

    #[Test]
    public function continuation_lines_inherit_vendor_group()
    {
        $formatter = new LogFormatter(100);

        // Pre-wrapped vendor frame followed by continuation
        $lines = [
            '[stacktrace]',
            '#0 /path/to/vendor/laravel/framework/src/Illuminate/Pipeline/Pipe',
            'line.php(50): Illuminate\Pipeline\Pipeline->handle()',
            '#1 /path/to/app/Controllers/HomeController.php(25): index()',
            '"}',
        ];

        $collection = $formatter->formatLines($lines);
        $lineObjects = $collection->getLines();

        // The vendor frame (line 1) and its continuation (line 2) should share vendor group
        $vendorFrame = $lineObjects[1];
        $continuation = $lineObjects[2];

        $this->assertTrue($vendorFrame->isVendorFrame, 'First part should be vendor');
        $this->assertTrue($continuation->isVendorFrame, 'Continuation should inherit vendor status');
        $this->assertEquals(
            $vendorFrame->vendorGroupId,
            $continuation->vendorGroupId,
            'Continuation should have same vendor group ID'
        );
    }

    #[Test]
    public function continuation_lines_collapse_with_vendor_group()
    {
        $formatter = new LogFormatter(100);
        $formatter->setContentWidth(100);

        // Pre-wrapped vendor frames
        $lines = [
            '[stacktrace]',
            '#0 /path/to/vendor/laravel/framework/src/Illuminate/Pipeline/Pipe',
            'line.php(50): method()',
            '#1 /path/to/vendor/laravel/framework/src/Illuminate/Routing/Route',
            '.php(60): dispatch()',
            '#2 /path/to/app/Controllers/HomeController.php(25): index()',
            '"}',
        ];

        $collection = $formatter->formatLines($lines);
        $collection->setContentWidth(100);

        // With vendor shown
        $collection->setHideVendor(false);
        $shownCount = $collection->getDisplayLineCount();

        // With vendor hidden
        $collection->setHideVendor(true);
        $hiddenCount = $collection->getDisplayLineCount();

        // Continuation lines should be collapsed too
        $this->assertLessThan($shownCount, $hiddenCount,
            'Hidden vendor should collapse continuations too');

        // Check no orphaned continuations (lines without borders)
        foreach ($collection->getAllDisplayLines() as $i => $line) {
            $plain = AnsiAware::plain($line);

            // Skip header/footer
            if (str_contains($plain, '╭') || str_contains($plain, '╰')) {
                continue;
            }

            // All other lines in trace should have borders
            if (str_contains($plain, '#') || str_contains($plain, 'line.php') || str_contains($plain, '.php')) {
                $this->assertStringContainsString('│', $plain,
                    "Line $i should have borders: ".substr($plain, 0, 50));
            }
        }
    }

    #[Test]
    public function non_vendor_continuation_lines_not_collapsed()
    {
        $formatter = new LogFormatter(100);

        // Pre-wrapped non-vendor frame
        $lines = [
            '[stacktrace]',
            '#0 /path/to/app/Http/Controllers/VeryLongControllerName',
            'Controller.php(50): handleRequest()',
            '"}',
        ];

        $collection = $formatter->formatLines($lines);
        $lineObjects = $collection->getLines();

        // Continuation of non-vendor frame should not be marked as vendor
        $continuation = $lineObjects[2];
        $this->assertFalse($continuation->isVendorFrame,
            'Non-vendor continuation should not be marked as vendor');
    }

    #[Test]
    public function in_stack_trace_state_resets_on_footer()
    {
        $formatter = new LogFormatter(100);

        // First exception with trace
        $lines1 = [
            '[2025-01-01] ERROR: First error {"exception":"[object] (Exception)',
            '[stacktrace]',
            '#0 /path/to/file.php(10): method()',
            '"}',
        ];

        // Format first exception
        $formatter->formatLines($lines1);

        // Now a regular log line (should NOT be treated as continuation)
        $regularLine = '[2025-01-01] INFO: Just a normal log message';
        $lineObj = $formatter->formatLine($regularLine, 100);

        // Should be formatted as regular line (no borders)
        $plain = AnsiAware::plain($lineObj->formattedLines[0]);
        $this->assertStringNotContainsString('│', $plain,
            'Regular line after trace should not have borders');
    }

    #[Test]
    public function continuation_between_vendor_groups_breaks_group()
    {
        $formatter = new LogFormatter(100);

        $lines = [
            '[stacktrace]',
            '#0 /path/to/vendor/laravel/Pipeline.php(50): method()',
            '#1 /path/to/app/Controllers/Home.php(25): index()',  // non-vendor breaks group
            '#2 /path/to/vendor/symfony/Kernel.php(100): handle()',
            '"}',
        ];

        $collection = $formatter->formatLines($lines);
        $lineObjects = $collection->getLines();

        // Lines 1 and 4 are vendor but should have different group IDs
        $this->assertNotEquals(
            $lineObjects[1]->vendorGroupId,
            $lineObjects[4]->vendorGroupId,
            'Vendor frames separated by non-vendor should have different group IDs'
        );
    }

    #[Test]
    public function multiple_exceptions_each_get_proper_trace_handling()
    {
        $formatter = new LogFormatter(100);

        $lines = [
            // First exception
            '[2025-01-01] ERROR: First {"exception":"[object] (Exception)',
            '[stacktrace]',
            '#0 /vendor/laravel/Pipeline.php(50): method()',
            'Continuation of first trace',
            '"}',
            // Second exception
            '[2025-01-01] ERROR: Second {"exception":"[object] (Exception)',
            '[stacktrace]',
            '#0 /vendor/symfony/Kernel.php(100): handle()',
            'Continuation of second trace',
            '"}',
        ];

        $collection = $formatter->formatLines($lines);

        // All lines inside traces should have borders
        $inTrace = false;
        foreach ($collection->getAllDisplayLines() as $i => $line) {
            $plain = AnsiAware::plain($line);

            if (str_contains($plain, '╭─Trace')) {
                $inTrace = true;

                continue;
            }
            if (str_contains($plain, '╰═')) {
                $inTrace = false;

                continue;
            }

            if ($inTrace) {
                $this->assertStringContainsString('│', $plain,
                    "Line $i inside trace should have borders");
            }
        }
    }
}
