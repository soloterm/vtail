<?php

namespace SoloTerm\Vtail\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SoloTerm\Vtail\Formatting\LogFormatter;

class LogFormatterTest extends TestCase
{
    protected LogFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new LogFormatter(120);
    }

    #[Test]
    public function it_detects_vendor_frames_from_content()
    {
        // Vendor frame
        $this->assertTrue($this->formatter->isVendorFrame('#3 /path/to/vendor/laravel/framework/src/Something.php'));

        // Non-vendor frame
        $this->assertFalse($this->formatter->isVendorFrame('#2 /path/to/app/Http/Controller.php'));

        // {main} is vendor
        $this->assertTrue($this->formatter->isVendorFrame('#67 {main}'));
    }

    #[Test]
    public function it_marks_vendor_frames_in_line_collection()
    {
        // Format some lines and check vendor frame detection in Line objects
        $lines = [
            "\e[0m#1 /vendor/laravel/framework/Pipeline.php(50): method()",
            "\e[0m#2 /vendor/laravel/framework/Pipeline.php(60): method()",
            "\e[0m#3 /app/Http/Controllers/SomeController.php(45): handle()",
        ];

        $collection = $this->formatter->formatLines($lines);
        $lineObjects = $collection->getLines();

        $this->assertTrue($lineObjects[0]->isVendorFrame, 'First line should be vendor');
        $this->assertTrue($lineObjects[1]->isVendorFrame, 'Second line should be vendor');
        $this->assertFalse($lineObjects[2]->isVendorFrame, 'Third line should not be vendor');

        // Consecutive vendor frames should have same group ID
        $this->assertEquals($lineObjects[0]->vendorGroupId, $lineObjects[1]->vendorGroupId);
    }

    #[Test]
    public function it_collapses_vendor_frames_via_collection()
    {
        $lines = [
            "\e[0m#1 /vendor/laravel/framework/Pipeline.php(50): method()",
            "\e[0m#2 /vendor/laravel/framework/Pipeline.php(60): method()",
            "\e[0m#3 /vendor/laravel/framework/Pipeline.php(70): method()",
            "\e[0m#4 /app/Http/Controllers/SomeController.php(45): handle()",
            "\e[0m#5 /vendor/laravel/framework/Kernel.php(80): method()",
        ];

        $collection = $this->formatter->formatLines($lines);

        // Without hiding vendor, all lines shown
        $collection->setHideVendor(false);
        $displayLinesShown = $collection->getDisplayLineCount();

        // With hiding vendor, consecutive vendor frames collapsed
        $collection->setHideVendor(true);
        $displayLinesHidden = $collection->getDisplayLineCount();

        $this->assertLessThan($displayLinesShown, $displayLinesHidden,
            'Hidden vendor should have fewer display lines');
    }

    #[Test]
    public function it_formats_stack_trace_lines_with_vendor_marker()
    {
        // A vendor frame line (has /vendor/ in path)
        $line = "\e[0m#3 /Users/aaron/Code/fusioncast/vendor/laravel/framework/src/Illuminate/Routing/Route.php(266): Something";

        $lineObj = $this->formatter->formatLine($line, 0);

        // Vendor frames should be marked as such and have a groupId
        // (collapsing to #… now happens in LineCollection, not LogFormatter)
        $this->assertTrue($lineObj->isVendorFrame);
        $this->assertNotNull($lineObj->vendorGroupId);
    }

    #[Test]
    public function it_formats_non_vendor_stack_trace_lines()
    {
        // A non-vendor frame line
        $line = "\e[0m#2 /Users/aaron/Code/app/Http/Controllers/SomeController.php(45): handle()";

        $lineObj = $this->formatter->formatLine($line, 0);

        // Non-vendor frames should be shown in full
        $this->assertFalse($lineObj->isVendorFrame);
        $output = implode("\n", $lineObj->formattedLines);
        $this->assertStringContainsString('SomeController', $output);
    }

    #[Test]
    public function it_formats_exception_header()
    {
        $line = '[2025-02-03 13:03:19] local.ERROR: Something went wrong {"exception":"[object] (TypeError: message here)';

        $lineObj = $this->formatter->formatLine($line, 0);

        $this->assertNotNull($lineObj);
        $this->assertNotEmpty($lineObj->formattedLines);
    }

    #[Test]
    public function it_formats_stacktrace_header()
    {
        $line = '[stacktrace]';

        $lineObj = $this->formatter->formatLine($line, 0);

        $this->assertNotNull($lineObj);
        $this->assertStringContainsString('Trace', $lineObj->formattedLines[0]);
    }

    #[Test]
    public function no_invisible_markers_in_formatted_output()
    {
        // Format various line types and ensure no SGR 8 codes are used
        $lines = [
            '[2025-01-01] local.ERROR: Test {"exception":"[object] (Exception at /app/file.php:1)',
            '[stacktrace]',
            "\e[0m#0 /app/vendor/laravel/framework/Pipeline.php(50): method()",
            "\e[0m#1 /app/src/Controllers/TestController.php(25): action()",
            '"}',
        ];

        foreach ($lines as $index => $line) {
            $lineObj = $this->formatter->formatLine($line, $index);

            if ($lineObj === null) {
                continue;
            }

            foreach ($lineObj->formattedLines as $formattedLine) {
                $this->assertStringNotContainsString("\e[8m", $formattedLine,
                    'Should not contain SGR 8 (hidden text)');
            }
        }
    }
}
