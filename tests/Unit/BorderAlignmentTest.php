<?php

namespace SoloTerm\Vtail\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SoloTerm\Vtail\Formatting\AnsiAware;
use SoloTerm\Vtail\Formatting\LogFormatter;

/**
 * Tests for trace box border alignment.
 *
 * The right border │ should align with the ╮ and ╯ corner characters.
 * All content lines should be exactly contentWidth characters wide.
 */
class BorderAlignmentTest extends TestCase
{
    #[Test]
    public function header_footer_and_content_have_same_width()
    {
        $width = 100;
        $formatter = new LogFormatter($width);

        $lines = [
            '[stacktrace]',
            '#0 /path/to/file.php(50): method()',
            '"}',
        ];

        $collection = $formatter->formatLines($lines);
        $lineObjects = $collection->getLines();

        // Get the formatted lines
        $header = $lineObjects[0]->formattedLines[0];  // ╭─Trace...╮
        $content = $lineObjects[1]->formattedLines[0]; // │ ... │
        $footer = $lineObjects[2]->formattedLines[0];  // ╰═══...═╯

        $headerWidth = AnsiAware::mb_strlen($header);
        $contentWidth = AnsiAware::mb_strlen($content);
        $footerWidth = AnsiAware::mb_strlen($footer);

        $this->assertEquals($width, $headerWidth, "Header should be exactly {$width} chars");
        $this->assertEquals($width, $contentWidth, "Content should be exactly {$width} chars");
        $this->assertEquals($width, $footerWidth, "Footer should be exactly {$width} chars");
    }

    #[Test]
    public function right_border_aligns_with_corners()
    {
        $width = 80;
        $formatter = new LogFormatter($width);

        $lines = [
            '[stacktrace]',
            '#0 /path/to/file.php(50): method()',
            '"}',
        ];

        $collection = $formatter->formatLines($lines);
        $lineObjects = $collection->getLines();

        $header = AnsiAware::plain($lineObjects[0]->formattedLines[0]);
        $content = AnsiAware::plain($lineObjects[1]->formattedLines[0]);
        $footer = AnsiAware::plain($lineObjects[2]->formattedLines[0]);

        // Find position of closing characters
        $headerClose = mb_strrpos($header, '╮');
        $contentClose = mb_strrpos($content, '│');
        $footerClose = mb_strrpos($footer, '╯');

        $this->assertEquals($headerClose, $contentClose,
            "Content │ should align with header ╮ (header: $headerClose, content: $contentClose)");
        $this->assertEquals($headerClose, $footerClose,
            "Footer ╯ should align with header ╮ (header: $headerClose, footer: $footerClose)");
    }

    #[Test]
    public function collapsed_vendor_marker_has_correct_width()
    {
        $width = 100;
        $formatter = new LogFormatter($width);

        $lines = [
            '[stacktrace]',
            '#0 /path/to/vendor/laravel/Pipeline.php(50): method()',
            '#1 /path/to/vendor/laravel/Router.php(60): dispatch()',
            '#2 /path/to/app/Controllers/Home.php(25): index()',
            '"}',
        ];

        $collection = $formatter->formatLines($lines);
        $collection->setContentWidth($width);
        $collection->setHideVendor(true);

        $displayLines = $collection->getAllDisplayLines();

        // Find the collapsed marker line
        $markerLine = null;
        foreach ($displayLines as $line) {
            if (str_contains($line, '#…')) {
                $markerLine = $line;
                break;
            }
        }

        $this->assertNotNull($markerLine, 'Should have a collapsed vendor marker');

        $markerWidth = AnsiAware::mb_strlen($markerLine);
        $this->assertEquals($width, $markerWidth,
            "Collapsed marker should be exactly {$width} chars, got {$markerWidth}");
    }

    #[Test]
    public function wrapped_content_lines_have_correct_width()
    {
        $width = 80;  // Narrow to force wrapping
        $formatter = new LogFormatter($width);

        // Long line that will wrap
        $longFrame = '#0 /path/to/vendor/laravel/framework/src/Illuminate/Foundation/Http/Kernel.php(144): Illuminate\Foundation\Http\Kernel->sendRequestThroughRouter(Object(Request))';

        $lines = [
            '[stacktrace]',
            $longFrame,
            '"}',
        ];

        $collection = $formatter->formatLines($lines);
        $lineObjects = $collection->getLines();

        // The stack frame should have multiple formatted lines due to wrapping
        $stackFrame = $lineObjects[1];

        foreach ($stackFrame->formattedLines as $i => $line) {
            $lineWidth = AnsiAware::mb_strlen($line);
            $this->assertEquals($width, $lineWidth,
                "Wrapped line $i should be exactly {$width} chars, got {$lineWidth}");
        }
    }

    #[Test]
    public function content_lines_do_not_exceed_terminal_width()
    {
        $width = 100;
        $formatter = new LogFormatter($width);

        $lines = [
            '[2025-01-01] ERROR: Test {"exception":"[object] (Exception at /file.php:1)',
            '[stacktrace]',
            '#0 /vendor/laravel/Pipeline.php(50): Illuminate\Pipeline\Pipeline->handle()',
            '#1 /vendor/laravel/Router.php(60): Illuminate\Routing\Router->dispatch()',
            '#2 /app/Controllers/HomeController.php(25): App\Http\Controllers\HomeController->index()',
            '"}',
        ];

        $collection = $formatter->formatLines($lines);

        foreach ($collection->getAllDisplayLines() as $i => $line) {
            $lineWidth = AnsiAware::mb_strlen($line);
            $this->assertLessThanOrEqual($width, $lineWidth,
                "Line $i exceeds terminal width ({$lineWidth} > {$width})");
        }
    }

    #[Test]
    public function border_structure_is_correct()
    {
        $formatter = new LogFormatter(100);

        $lines = [
            '[stacktrace]',
            '#0 /path/to/file.php(50): method()',
            '"}',
        ];

        $collection = $formatter->formatLines($lines);
        $lineObjects = $collection->getLines();

        // Header should be: " ╭─Trace" + dashes + "╮"
        $header = AnsiAware::plain($lineObjects[0]->formattedLines[0]);
        $this->assertStringStartsWith(' ╭─Trace', $header);
        $this->assertStringEndsWith('╮', $header);

        // Content should be: " │ " + content + " │"
        $content = AnsiAware::plain($lineObjects[1]->formattedLines[0]);
        $this->assertStringStartsWith(' │ ', $content);
        $this->assertStringEndsWith(' │', $content);  // Note: no trailing space

        // Footer should be: " ╰" + equals + "╯"
        $footer = AnsiAware::plain($lineObjects[2]->formattedLines[0]);
        $this->assertStringStartsWith(' ╰', $footer);
        $this->assertStringEndsWith('╯', $footer);
    }
}
