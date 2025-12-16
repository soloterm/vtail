<?php

namespace SoloTerm\Vtail\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SoloTerm\Vtail\Formatting\AnsiAware;

/**
 * Tests that verify rendering quality - no broken borders, stray characters, etc.
 *
 * These tests render vtail output through a virtual Screen and assert that the
 * visual output is clean and correct.
 */
class RenderingQualityTest extends TestCase
{
    protected function getFixturePath(string $name): string
    {
        return __DIR__.'/../Fixtures/'.$name;
    }

    #[Test]
    public function trace_box_has_consistent_borders()
    {
        $app = new TestableApplication;
        $app->setDimensions(120, 50);

        // Load a real exception with stack trace
        $lines = $this->getSimpleStackTrace();
        $app->addLines($lines);

        $app->renderFrame();
        $formatted = $app->getFormattedLines();

        // Find all lines that should be inside the trace box
        $inTraceBox = false;
        $traceBoxLines = [];

        foreach ($formatted as $i => $line) {
            $plain = AnsiAware::plain($line);

            // Trace box starts with ╭─Trace
            if (str_contains($plain, '╭─Trace') || str_contains($plain, '╭─')) {
                $inTraceBox = true;
                $traceBoxLines[] = ['index' => $i, 'plain' => $plain, 'raw' => $line, 'type' => 'header'];

                continue;
            }

            // Trace box ends with ╰═
            if (str_contains($plain, '╰═') || str_contains($plain, '╰')) {
                $traceBoxLines[] = ['index' => $i, 'plain' => $plain, 'raw' => $line, 'type' => 'footer'];
                $inTraceBox = false;

                continue;
            }

            if ($inTraceBox) {
                $traceBoxLines[] = ['index' => $i, 'plain' => $plain, 'raw' => $line, 'type' => 'content'];
            }
        }

        $this->assertNotEmpty($traceBoxLines, 'Should have trace box lines');

        // Check each content line has proper borders
        foreach ($traceBoxLines as $lineInfo) {
            if ($lineInfo['type'] === 'content') {
                $plain = $lineInfo['plain'];

                // Content lines should start with │ and end with │
                $this->assertStringStartsWith(' │', $plain,
                    "Line {$lineInfo['index']} should start with ' │': ".substr($plain, 0, 50));

                // Line should end with │ followed by space (no more invisible markers!)
                $trimmed = rtrim($plain);
                $this->assertMatchesRegularExpression('/│ ?$/', $trimmed,
                    "Line {$lineInfo['index']} should end with '│' (+ optional space): ...".substr($trimmed, -30));
            }
        }
    }

    #[Test]
    public function no_stray_characters_outside_content()
    {
        $app = new TestableApplication;
        $app->setDimensions(120, 50);

        $lines = $this->getSimpleStackTrace();
        $app->addLines($lines);

        $app->renderFrame();
        $plainRows = $app->getPlainRows();

        // Skip status bar (row 0) and hotkey bar (last row)
        $contentRows = array_slice($plainRows, 1, -1);

        foreach ($contentRows as $i => $row) {
            $actualRow = $i + 1; // Account for skipped status bar

            // No stray single characters at the end of lines (like the "D" in screenshot)
            // After trimming, line shouldn't end with a single letter preceded by whitespace
            if (preg_match('/\s+([A-Z])\s*$/', $row, $matches)) {
                $this->fail(
                    "Row {$actualRow} has stray character '{$matches[1]}' at end: ...".
                    substr($row, -40)
                );
            }
        }

        $this->assertTrue(true, 'No stray characters found');
    }

    #[Test]
    public function wrapped_lines_maintain_trace_box_structure()
    {
        $app = new TestableApplication;
        $app->setDimensions(100, 40); // Narrower to force wrapping

        $lines = $this->getSimpleStackTrace();
        $app->addLines($lines);

        $app->renderFrame();
        $formatted = $app->getFormattedLines();

        // Find trace box content lines
        $inTraceBox = false;

        foreach ($formatted as $i => $line) {
            $plain = AnsiAware::plain($line);

            if (str_contains($plain, '╭─Trace')) {
                $inTraceBox = true;

                continue;
            }
            if (str_contains($plain, '╰═')) {
                $inTraceBox = false;

                continue;
            }

            if ($inTraceBox) {
                // Every line in trace box must have border characters
                $this->assertStringContainsString('│', $plain,
                    "Trace box line {$i} missing border: ".substr($plain, 0, 60));

                // Should not have random letters bleeding outside the box
                // The pattern should be: " │ content │ "
                $this->assertMatchesRegularExpression('/^\s*│.*│/', $plain,
                    "Line {$i} has broken structure: ".$plain);
            }
        }
    }

    #[Test]
    public function vendor_frames_are_detected_in_output()
    {
        $app = new TestableApplication;
        $app->setDimensions(120, 40);

        $lines = $this->getSimpleStackTrace();
        $app->addLines($lines);

        $app->renderFrame();
        $formatted = $app->getFormattedLines();

        // Check output contains vendor paths
        $output = implode("\n", $formatted);
        $this->assertStringContainsString('/vendor/', $output);
    }

    #[Test]
    public function no_invisible_markers_in_output()
    {
        // FIXED: We no longer use SGR 8 (hidden text) which wasn't supported by most terminals.

        $app = new TestableApplication;
        $app->setDimensions(120, 40);

        $lines = $this->getSimpleStackTrace();
        $app->addLines($lines);

        $app->renderFrame();
        $formatted = $app->getFormattedLines();

        // Check that no lines contain SGR 8 (hidden text) escape sequences
        foreach ($formatted as $i => $line) {
            $this->assertStringNotContainsString("\e[8m", $line,
                "Line {$i} should not contain SGR 8 (hidden text) escape sequence");
            $this->assertStringNotContainsString("\e[28m", $line,
                "Line {$i} should not contain SGR 28 (reveal) escape sequence");
        }

        // Plain output should not have V or W markers that were previously invisible
        $plainOutput = $app->getPlainOutput();

        // Check specifically for the pattern that was previously used: │V or │W at end of line
        $this->assertDoesNotMatchRegularExpression('/│[VW]\s*$/', $plainOutput,
            'Plain output should not have V/W markers after borders');
    }

    #[Test]
    public function status_bar_is_on_first_line()
    {
        $app = new TestableApplication('/tmp/test.log');
        $app->setDimensions(100, 30);

        $app->addLines(['[2025-01-01] Test message']);
        $app->renderFrame();

        $plainRows = $app->getPlainRows();

        // First row should be status bar with file info
        $this->assertStringContainsString('test.log', $plainRows[0]);
        $this->assertStringContainsString('Lines:', $plainRows[0]);
    }

    #[Test]
    public function hotkey_bar_is_on_last_line()
    {
        $app = new TestableApplication;
        $app->setDimensions(100, 30);

        $app->addLines(['[2025-01-01] Test message']);
        $app->renderFrame();

        $plainRows = $app->getPlainRows();

        // Last non-empty row should be hotkey bar
        $lastRow = '';
        for ($i = count($plainRows) - 1; $i >= 0; $i--) {
            if (trim($plainRows[$i]) !== '') {
                $lastRow = $plainRows[$i];
                break;
            }
        }

        $this->assertStringContainsString('quit', $lastRow, 'Hotkey bar should contain quit option');
        $this->assertStringContainsString('vendor', $lastRow, 'Hotkey bar should contain vendor option');
    }

    #[Test]
    public function all_formatted_lines_fit_within_terminal_width()
    {
        $app = new TestableApplication;
        $width = 100;
        $app->setDimensions($width, 40);

        $app->loadFixture($this->getFixturePath('enhance-log-wrap-vendor-test.log'));
        $app->renderFrame();

        $formatted = $app->getFormattedLines();

        foreach ($formatted as $i => $line) {
            $visibleLength = AnsiAware::mb_strlen($line);

            $this->assertLessThanOrEqual(
                $width,
                $visibleLength,
                "Line {$i} exceeds width ({$visibleLength} > {$width}): ".
                substr(AnsiAware::plain($line), 0, 80).'...'
            );
        }
    }

    #[Test]
    public function debug_actual_rendering_output()
    {
        // This test outputs the actual rendered content for visual inspection
        // Run with: ./vendor/bin/phpunit --filter debug_actual_rendering_output

        $app = new TestableApplication;
        $app->setDimensions(120, 35);

        // Use first exception from fixture
        $fixturePath = $this->getFixturePath('enhance-log-wrap-vendor-test.log');
        $allLines = file($fixturePath, FILE_IGNORE_NEW_LINES);
        $testLines = array_slice($allLines, 0, 20); // Just the beginning of first exception

        $app->addLines($testLines);
        $app->renderFrame();

        $formatted = $app->getFormattedLines();

        // Uncomment to see actual output:
        // echo "\n=== FORMATTED LINES ===\n";
        // foreach ($formatted as $i => $line) {
        //     $plain = AnsiAware::plain($line);
        //     printf("%3d: %s\n", $i, $plain);
        // }
        // echo "=== END ===\n";

        // Uncomment to see with ANSI visible:
        // echo "\n=== RAW WITH ANSI ===\n";
        // foreach ($formatted as $i => $line) {
        //     $visible = str_replace("\e", "\\e", $line);
        //     printf("%3d: %s\n", $i, substr($visible, 0, 150));
        // }

        $this->assertNotEmpty($formatted);
    }

    /**
     * Create a simple stack trace for testing.
     */
    protected function getSimpleStackTrace(): array
    {
        return [
            '[2025-01-01 12:00:00] local.ERROR: Test error message {"exception":"[object] (Exception(code: 0): Test error at /app/src/MyClass.php:100)',
            '[stacktrace]',
            "\e[0m#0 /app/vendor/laravel/framework/Pipeline.php(50): SomeClass->method()",
            "\e[0m#1 /app/src/Controllers/TestController.php(25): Pipeline->handle()",
            "\e[0m#2 /app/vendor/symfony/http-kernel/Kernel.php(100): Controller->action()",
            "\e[0m#3 /app/public/index.php(17): Kernel->handle()",
            "\e[0m#4 {main}",
            '"}',
        ];
    }

    #[Test]
    public function trace_box_closing_border_is_correct()
    {
        $app = new TestableApplication;
        $app->setDimensions(120, 40);

        $lines = $this->getSimpleStackTrace();
        $app->addLines($lines);

        $app->renderFrame();
        $formatted = $app->getFormattedLines();

        // Find the closing border
        $closingBorder = null;
        foreach ($formatted as $line) {
            $plain = AnsiAware::plain($line);
            if (str_contains($plain, '╰') && str_contains($plain, '═')) {
                $closingBorder = $plain;
                break;
            }
        }

        $this->assertNotNull($closingBorder, 'Should have closing border');

        // Closing border should be: " ╰════...════╯"
        $this->assertStringContainsString('╰', $closingBorder);
        $this->assertStringContainsString('╯', $closingBorder);
        $this->assertStringContainsString('═', $closingBorder);
    }

    #[Test]
    public function trace_box_header_is_correct()
    {
        $app = new TestableApplication;
        $app->setDimensions(120, 40);

        $lines = $this->getSimpleStackTrace();
        $app->addLines($lines);

        $app->renderFrame();
        $formatted = $app->getFormattedLines();

        // Find the trace header
        $traceHeader = null;
        foreach ($formatted as $line) {
            $plain = AnsiAware::plain($line);
            if (str_contains($plain, 'Trace')) {
                $traceHeader = $plain;
                break;
            }
        }

        $this->assertNotNull($traceHeader, 'Should have trace header');

        // Header should be: " ╭─Trace───...───╮"
        $this->assertStringContainsString('╭', $traceHeader);
        $this->assertStringContainsString('Trace', $traceHeader);
        $this->assertStringContainsString('╮', $traceHeader);
    }

    #[Test]
    public function wrap_produces_multiple_lines()
    {
        $app = new TestableApplication;
        $app->setDimensions(60, 30); // Narrow to force wrapping

        // Add a long line that will wrap
        $longLine = '[2025-01-01] '.str_repeat('A', 100);
        $app->addLines([$longLine]);

        $app->renderFrame();
        $formatted = $app->getFormattedLines();

        // Should have multiple formatted lines from one input line
        $this->assertGreaterThan(1, count($formatted), 'Long line should wrap into multiple lines');
    }

    #[Test]
    public function vendor_frames_collapse_when_hidden()
    {
        $app = new TestableApplication;
        $app->setDimensions(120, 40);

        // Use a stack trace with consecutive vendor frames that will collapse
        $lines = [
            '[2025-01-01] Error {"exception":"[object] (Exception at /app/file.php:1)',
            '[stacktrace]',
            "\e[0m#0 /app/vendor/laravel/framework/Pipeline.php(50): method()",
            "\e[0m#1 /app/vendor/laravel/framework/Router.php(30): route()",  // consecutive vendor
            "\e[0m#2 /app/src/Controllers/MyController.php(25): handle()",
            "\e[0m#3 /app/vendor/symfony/Kernel.php(100): run()",
            "\e[0m#4 /app/vendor/symfony/Request.php(50): handle()",  // consecutive vendor
            "\e[0m#5 {main}",  // consecutive vendor
            '"}',
        ];

        $app->addLines($lines);

        // Get line count with vendor shown
        $app->renderFrame();
        $countWithVendor = count($app->getFormattedLines());

        // Hide vendor frames
        $app->pressKey('v');
        $app->renderFrame();
        $countWithoutVendor = count($app->getFormattedLines());

        // Should have fewer lines when vendor is hidden (consecutive frames collapse)
        $this->assertLessThan($countWithVendor, $countWithoutVendor,
            "Hiding vendor should reduce line count. With: {$countWithVendor}, Without: {$countWithoutVendor}");

        // Should have compressed markers
        $output = implode("\n", $app->getFormattedLines());
        $this->assertStringContainsString('#…', $output, 'Should have compressed vendor markers');
    }
}
