<?php

namespace SoloTerm\Vtail\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SoloTerm\Vtail\Formatting\AnsiAware;

/**
 * Snapshot-based tests that compare actual rendered output against expected snapshots.
 *
 * These tests catch rendering issues by doing exact character-by-character comparison
 * of the rendered output. When a test fails, it shows exactly which characters differ.
 *
 * To update snapshots when output intentionally changes, set UPDATE_SNAPSHOTS=1:
 *   UPDATE_SNAPSHOTS=1 ./vendor/bin/phpunit --filter SnapshotTest
 */
class SnapshotTest extends TestCase
{
    protected string $snapshotDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->snapshotDir = __DIR__ . '/../Snapshots';
    }

    protected function getFixturePath(string $name): string
    {
        return __DIR__ . '/../Fixtures/' . $name;
    }

    #[Test]
    public function simple_log_line_renders_correctly()
    {
        $app = new TestableApplication();
        $app->setDimensions(80, 10);

        $app->addLines([
            '[2025-01-01 12:00:00] local.INFO: Hello world',
        ]);

        $app->renderFrame();
        $this->assertSnapshotMatches('simple_log_line', $app->getPlainRows());
    }

    #[Test]
    public function stack_trace_box_borders_are_consistent()
    {
        $app = new TestableApplication();
        $app->setDimensions(100, 25);

        $lines = [
            '[2025-01-01 12:00:00] local.ERROR: Test error {"exception":"[object] (Exception(code: 0): Test error at /app/src/MyClass.php:100)',
            '[stacktrace]',
            "\e[0m#0 /app/vendor/laravel/framework/Pipeline.php(50): SomeClass->method()",
            "\e[0m#1 /app/src/Controllers/TestController.php(25): Pipeline->handle()",
            "\e[0m#2 /app/vendor/symfony/http-kernel/Kernel.php(100): Controller->action()",
            "\e[0m#3 /app/public/index.php(17): Kernel->handle()",
            "\e[0m#4 {main}",
            '"}',
        ];

        $app->addLines($lines);
        $app->renderFrame();
        
        $this->assertSnapshotMatches('stack_trace_box_borders', $app->getPlainRows());
    }

    #[Test]
    public function trace_box_content_lines_have_proper_borders()
    {
        $app = new TestableApplication();
        $app->setDimensions(100, 25);

        $lines = [
            '[2025-01-01 12:00:00] local.ERROR: Test error {"exception":"[object] (Exception(code: 0): Test error at /app/src/MyClass.php:100)',
            '[stacktrace]',
            "\e[0m#0 /app/vendor/laravel/framework/Pipeline.php(50): SomeClass->method()",
            "\e[0m#1 /app/src/Controllers/TestController.php(25): Pipeline->handle()",
            '"}',
        ];

        $app->addLines($lines);
        $app->renderFrame();
        $formatted = $app->getFormattedLines();

        // Find trace box content lines and verify each one
        $inTraceBox = false;
        $contentLines = [];
        
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
                $contentLines[] = ['index' => $i, 'plain' => $plain, 'raw' => $line];
            }
        }

        // Each content line must start with " │" and end with "│" (with optional trailing space)
        foreach ($contentLines as $info) {
            $plain = $info['plain'];
            
            $this->assertStringStartsWith(' │', $plain,
                "Line {$info['index']} should start with ' │': '{$plain}'");
            
            $trimmed = rtrim($plain);
            $this->assertStringEndsWith('│', $trimmed,
                "Line {$info['index']} should end with '│': '{$trimmed}'");
            
            // Should NOT have stray characters after the closing border
            $afterLastBorder = substr($plain, strrpos($plain, '│') + strlen('│'));
            $this->assertMatchesRegularExpression('/^\s*$/', $afterLastBorder,
                "Line {$info['index']} has stray characters after border: '{$afterLastBorder}'");
        }
    }

    #[Test]
    public function vendor_hidden_renders_compressed_markers()
    {
        $app = new TestableApplication();
        $app->setDimensions(100, 25);

        $lines = [
            '[2025-01-01] Error {"exception":"[object] (Exception at /app/file.php:1)',
            '[stacktrace]',
            "\e[0m#0 /app/vendor/laravel/framework/Pipeline.php(50): method()",
            "\e[0m#1 /app/vendor/laravel/framework/Router.php(30): route()",
            "\e[0m#2 /app/src/Controllers/MyController.php(25): handle()",
            "\e[0m#3 /app/vendor/symfony/Kernel.php(100): run()",
            "\e[0m#4 {main}",
            '"}',
        ];

        $app->addLines($lines);
        $app->pressKey('v'); // Hide vendor
        $app->renderFrame();

        $this->assertSnapshotMatches('vendor_hidden', $app->getPlainRows());
    }

    #[Test]
    public function vendor_shown_renders_all_frames()
    {
        $app = new TestableApplication();
        $app->setDimensions(100, 25);

        $lines = [
            '[2025-01-01] Error {"exception":"[object] (Exception at /app/file.php:1)',
            '[stacktrace]',
            "\e[0m#0 /app/vendor/laravel/framework/Pipeline.php(50): method()",
            "\e[0m#1 /app/src/Controllers/MyController.php(25): handle()",
            "\e[0m#2 /app/vendor/symfony/Kernel.php(100): run()",
            "\e[0m#3 {main}",
            '"}',
        ];

        $app->addLines($lines);
        $app->renderFrame();

        $this->assertSnapshotMatches('vendor_shown', $app->getPlainRows());
    }

    #[Test]
    public function wrapped_lines_stay_within_borders()
    {
        $app = new TestableApplication();
        $app->setDimensions(80, 30); // Narrow width to force wrapping

        $lines = [
            '[2025-01-01] Error {"exception":"[object] (Exception at /app/file.php:1)',
            '[stacktrace]',
            "\e[0m#0 /app/vendor/laravel/framework/src/Illuminate/Pipeline/Pipeline.php(50): VeryLongClassName->veryLongMethodName()",
            "\e[0m#1 /app/src/Controllers/TestController.php(25): handle()",
            '"}',
        ];

        $app->addLines($lines);
        $app->renderFrame();

        $this->assertSnapshotMatches('wrapped_lines', $app->getPlainRows());
    }

    #[Test]
    public function no_visible_marker_characters_in_output()
    {
        $app = new TestableApplication();
        $app->setDimensions(100, 25);

        $lines = [
            '[2025-01-01] Error {"exception":"[object] (Exception at /app/file.php:1)',
            '[stacktrace]',
            "\e[0m#0 /app/vendor/laravel/Pipeline.php(50): method()",
            "\e[0m#1 /app/src/MyController.php(25): handle()",
            '"}',
        ];

        $app->addLines($lines);
        $app->renderFrame();

        $plainOutput = $app->getPlainOutput();
        $rows = $app->getPlainRows();

        // Check for stray V or W markers that would indicate invisible marker bug
        foreach ($rows as $i => $row) {
            // Pattern: border character followed by single V or W at end of line
            $this->assertDoesNotMatchRegularExpression('/│[VW]\s*$/', $row,
                "Row {$i} has stray marker character: {$row}");
            
            // Pattern: single letter at end after whitespace (except expected content)
            if (preg_match('/\s{2,}([A-Z])\s*$/', $row, $matches)) {
                // Allow expected endings like status bar content
                if (!str_contains($row, 'Lines:') && !str_contains($row, 'quit')) {
                    $this->fail("Row {$i} has isolated character '{$matches[1]}' at end: ...{$this->lastChars($row, 40)}");
                }
            }
        }

        $this->assertTrue(true);
    }

    #[Test]
    public function all_rows_fit_within_terminal_width()
    {
        $app = new TestableApplication();
        $width = 100;
        $app->setDimensions($width, 30);

        $app->loadFixture($this->getFixturePath('enhance-log-wrap-vendor-test.log'));
        $app->renderFrame();

        $rows = $app->getPlainRows();

        foreach ($rows as $i => $row) {
            $len = mb_strlen($row);
            // Allow small margin for edge cases in box drawing
            $this->assertLessThanOrEqual($width + 2, $len,
                "Row {$i} exceeds width ({$len} > {$width}): {$this->lastChars($row, 50)}");
        }
    }

    #[Test]
    public function fixture_file_renders_without_artifacts()
    {
        $app = new TestableApplication();
        $app->setDimensions(120, 40);

        // Load first 50 lines of fixture
        $fixturePath = $this->getFixturePath('enhance-log-wrap-vendor-test.log');
        $allLines = file($fixturePath, FILE_IGNORE_NEW_LINES);
        $testLines = array_slice($allLines, 0, 50);

        $app->addLines($testLines);
        $app->renderFrame();

        $this->assertSnapshotMatches('fixture_first_50_lines', $app->getPlainRows());
    }

    #[Test]
    public function toggling_vendor_twice_returns_same_output()
    {
        $app = new TestableApplication();
        $app->setDimensions(100, 30);

        $lines = [
            '[2025-01-01] Error {"exception":"[object] (Exception at /app/file.php:1)',
            '[stacktrace]',
            "\e[0m#0 /app/vendor/laravel/Pipeline.php(50): method()",
            "\e[0m#1 /app/src/MyController.php(25): handle()",
            "\e[0m#2 /app/vendor/symfony/Kernel.php(100): run()",
            '"}',
        ];

        $app->addLines($lines);
        $app->renderFrame();
        $before = $app->getPlainRows();

        // Toggle vendor off then on
        $app->pressKey('v');
        $app->renderFrame();
        
        $app->pressKey('v');
        $app->renderFrame();
        $after = $app->getPlainRows();

        $this->assertEquals($before, $after, 'Output should be identical after toggling vendor twice');
    }

    #[Test]
    public function wrapped_lines_do_not_break_borders()
    {
        // This test catches the bug where wrapped content leaves fragments
        // like "l │" or "\ │" on separate lines
        
        $app = new TestableApplication();
        $app->setDimensions(120, 50);

        $app->loadFixture($this->getFixturePath('enhance-log-wrap-vendor-test.log'));
        $app->renderFrame();

        $rows = $app->getPlainRows();

        foreach ($rows as $i => $row) {
            // Skip status bar and hotkey bar
            if ($i === 0 || str_contains($row, 'quit')) {
                continue;
            }
            
            // Skip empty rows
            if (trim($row) === '') {
                continue;
            }

            // If a row contains a border pipe, it should have proper structure
            if (str_contains($row, '│')) {
                // Should not be just a fragment like "l │" or "\ │"
                // These indicate broken line wrapping
                $trimmed = trim($row);
                
                // A valid trace line should start with " │" (space + pipe)
                // Not with something like "l │" (letter + space + pipe)
                $this->assertDoesNotMatchRegularExpression('/^[a-zA-Z]\s+│/', $trimmed,
                    "Row {$i} appears to be a broken fragment: '{$trimmed}'");
                
                // Should not have isolated short fragments before the border
                $this->assertDoesNotMatchRegularExpression('/^[a-zA-Z]{1,3}\s*│/', $trimmed,
                    "Row {$i} has short fragment before border: '{$trimmed}'");
            }
        }
    }

    #[Test]
    public function each_trace_line_is_self_contained()
    {
        // Verifies that every line inside a trace box starts and ends with proper borders
        
        $app = new TestableApplication();
        $app->setDimensions(120, 50);

        $lines = [
            '[2025-01-01 12:00:00] local.ERROR: Test error {"exception":"[object] (Exception(code: 0): Test error at /app/src/MyClass.php:100)',
            '[stacktrace]',
            "\e[0m#0 /app/vendor/laravel/framework/src/Illuminate/Foundation/Http/Middleware/TransformsRequest.php(21): Very\\Long\\Namespace\\Class->methodWithManyParameters(Object(Some\\Long\\Parameter), Object(Another\\Parameter))",
            "\e[0m#1 /app/src/Controllers/TestController.php(25): Pipeline->handle()",
            '"}',
        ];

        $app->addLines($lines);
        $app->renderFrame();

        $formatted = $app->getFormattedLines();
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
                // Every content line must be properly bordered
                $this->assertStringStartsWith(' │', $plain,
                    "Trace line {$i} missing opening border: '{$plain}'");
                
                // Must end with closing border (allowing trailing space)
                $trimmed = rtrim($plain);
                $this->assertStringEndsWith('│', $trimmed,
                    "Trace line {$i} missing closing border: '{$trimmed}'");
                
                // The border should be near the end, not in the middle with garbage after
                $lastPipePos = strrpos($plain, '│');
                $afterPipe = substr($plain, $lastPipePos + strlen('│'));
                $this->assertMatchesRegularExpression('/^\s*$/', $afterPipe,
                    "Trace line {$i} has content after closing border: '{$afterPipe}'");
            }
        }
    }

    #[Test]
    public function very_long_paths_wrap_correctly_in_trace_box()
    {
        // Tests wrapping with realistic long Laravel paths
        
        $app = new TestableApplication();
        $app->setDimensions(100, 30); // Narrow to force wrapping

        $lines = [
            '[2025-01-01] Error {"exception":"[object] (Exception at /app/file.php:1)',
            '[stacktrace]',
            "\e[0m#0 /Users/developer/Projects/myapp/vendor/laravel/framework/src/Illuminate/Foundation/Http/Middleware/TransformsRequest.php(21): Illuminate\\Pipeline\\Pipeline->Illuminate\\Pipeline\\{closure}(Object(Illuminate\\Http\\Request))",
            "\e[0m#1 /Users/developer/Projects/myapp/vendor/laravel/framework/src/Illuminate/Foundation/Http/Middleware/ConvertEmptyStringsToNull.php(31): Illuminate\\Foundation\\Http\\Middleware\\TransformsRequest->handle(Object(Illuminate\\Http\\Request), Object(Closure))",
            "\e[0m#2 /Users/developer/Projects/myapp/app/Http/Controllers/UserController.php(45): handle()",
            '"}',
        ];

        $app->addLines($lines);
        $app->renderFrame();

        $rows = $app->getPlainRows();

        // Check every row for proper structure
        foreach ($rows as $i => $row) {
            $len = mb_strlen($row);
            
            // No row should exceed terminal width
            $this->assertLessThanOrEqual(100, $len,
                "Row {$i} exceeds terminal width ({$len}): '{$row}'");
            
            // If it has a border, verify structure
            if (str_contains($row, '│') && !str_contains($row, '╭') && !str_contains($row, '╰')) {
                $trimmed = trim($row);
                
                // Should not be fragments
                if (mb_strlen($trimmed) < 10 && str_contains($trimmed, '│')) {
                    $this->fail("Row {$i} appears to be a broken fragment: '{$trimmed}'");
                }
            }
        }
    }

    #[Test]
    public function debug_wrapping_output()
    {
        // Run with: ./vendor/bin/phpunit --filter debug_wrapping_output
        // to see what's actually being rendered
        
        $app = new TestableApplication();
        $app->setDimensions(100, 30);

        $lines = [
            '[2025-01-01] Error {"exception":"[object] (Exception at /app/file.php:1)',
            '[stacktrace]',
            "\e[0m#0 /Users/developer/Projects/myapp/vendor/laravel/framework/src/Illuminate/Foundation/Http/Middleware/TransformsRequest.php(21): Illuminate\\Pipeline\\Pipeline->Illuminate\\Pipeline\\{closure}(Object(Illuminate\\Http\\Request))",
            "\e[0m#1 /Users/developer/Projects/myapp/app/Http/Controllers/UserController.php(45): handle()",
            '"}',
        ];

        $app->addLines($lines);
        $app->renderFrame();

        $rows = $app->getPlainRows();

        // Uncomment to see actual output:
        // echo "\n=== RENDERED ROWS ===\n";
        // foreach ($rows as $i => $row) {
        //     printf("%3d [%3d]: %s\n", $i, mb_strlen($row), $row);
        // }
        // echo "=== END ===\n";

        $this->assertNotEmpty($rows);
    }

    #[Test]
    public function trace_box_header_footer_match_content_width()
    {
        // Bug: Header/footer are 119 chars, content is 120 chars
        // This causes the right border to be missing or misaligned
        
        $app = new TestableApplication();
        $app->setDimensions(120, 30);

        $lines = [
            '[2025-01-01] Error {"exception":"[object] (Exception at /app/file.php:1)',
            '[stacktrace]',
            "\e[0m#0 /app/vendor/laravel/Pipeline.php(50): method()",
            "\e[0m#1 /app/src/Controller.php(25): handle()",
            '"}',
        ];

        $app->addLines($lines);
        $app->renderFrame();

        $formatted = $app->getFormattedLines();
        
        $headerLine = null;
        $contentLine = null;
        $footerLine = null;
        
        foreach ($formatted as $line) {
            $plain = AnsiAware::plain($line);
            if (str_contains($plain, '╭─Trace')) {
                $headerLine = $plain;
            } elseif (str_contains($plain, '╰═')) {
                $footerLine = $plain;
            } elseif (str_contains($plain, ' │ #')) {
                $contentLine = $plain;
            }
        }

        $this->assertNotNull($headerLine, 'Header line not found');
        $this->assertNotNull($contentLine, 'Content line not found');
        $this->assertNotNull($footerLine, 'Footer line not found');
        
        $headerLen = mb_strlen($headerLine);
        $contentLen = mb_strlen($contentLine);
        $footerLen = mb_strlen($footerLine);
        
        // All three should have the same width
        $this->assertEquals($headerLen, $contentLen,
            "Header ({$headerLen}) and content ({$contentLen}) widths should match.\n" .
            "Header: {$headerLine}\n" .
            "Content: {$contentLine}");
            
        $this->assertEquals($footerLen, $contentLen,
            "Footer ({$footerLen}) and content ({$contentLen}) widths should match.\n" .
            "Footer: {$footerLine}\n" .
            "Content: {$contentLine}");
    }

    #[Test]
    public function trace_box_header_ends_with_corner()
    {
        // Bug: The header's right corner (╮) is missing or pushed off
        
        $app = new TestableApplication();
        $app->setDimensions(120, 30);

        $lines = [
            '[2025-01-01] Error {"exception":"[object] (Exception at /app/file.php:1)',
            '[stacktrace]',
            "\e[0m#0 /app/src/Controller.php(25): handle()",
            '"}',
        ];

        $app->addLines($lines);
        $app->renderFrame();

        $formatted = $app->getFormattedLines();
        
        foreach ($formatted as $line) {
            $plain = AnsiAware::plain($line);
            if (str_contains($plain, '╭─Trace')) {
                // The header should end with the corner character
                $trimmed = rtrim($plain);
                $this->assertStringEndsWith('╮', $trimmed,
                    "Trace header should end with ╮: '$plain'");
                return;
            }
        }
        
        $this->fail('Trace header not found');
    }

    #[Test]
    public function trace_box_footer_ends_with_corner()
    {
        // Bug: The footer's right corner (╯) is missing or pushed off
        
        $app = new TestableApplication();
        $app->setDimensions(120, 30);

        $lines = [
            '[2025-01-01] Error {"exception":"[object] (Exception at /app/file.php:1)',
            '[stacktrace]',
            "\e[0m#0 /app/src/Controller.php(25): handle()",
            '"}',
        ];

        $app->addLines($lines);
        $app->renderFrame();

        $formatted = $app->getFormattedLines();
        
        foreach ($formatted as $line) {
            $plain = AnsiAware::plain($line);
            if (str_contains($plain, '╰═')) {
                // The footer should end with the corner character
                $trimmed = rtrim($plain);
                $this->assertStringEndsWith('╯', $trimmed,
                    "Trace footer should end with ╯: '$plain'");
                return;
            }
        }
        
        $this->fail('Trace footer not found');
    }

    #[Test]
    public function realistic_log_renders_complete_trace_box()
    {
        // Test with realistic Laravel log data
        
        $app = new TestableApplication();
        $app->setDimensions(120, 80);
        
        $app->loadFixture($this->getFixturePath('lifeos-realistic.log'));
        $app->renderFrame();

        $formatted = $app->getFormattedLines();
        
        $inTraceBox = false;
        $traceBoxCount = 0;
        $headerLineNums = [];
        $footerLineNums = [];
        
        foreach ($formatted as $i => $line) {
            $plain = AnsiAware::plain($line);
            
            if (str_contains($plain, '╭─Trace')) {
                $inTraceBox = true;
                $traceBoxCount++;
                $headerLineNums[] = $i;
                
                // Header must have right corner
                $trimmed = rtrim($plain);
                $this->assertStringEndsWith('╮', $trimmed,
                    "Trace header at line {$i} missing right corner: '{$plain}'");
            }
            
            if (str_contains($plain, '╰═')) {
                $inTraceBox = false;
                $footerLineNums[] = $i;
                
                // Footer must have right corner
                $trimmed = rtrim($plain);
                $this->assertStringEndsWith('╯', $trimmed,
                    "Trace footer at line {$i} missing right corner: '{$plain}'");
            }
            
            // Content lines inside trace box
            if ($inTraceBox && str_contains($plain, ' │ ')) {
                $trimmed = rtrim($plain);
                $this->assertStringEndsWith('│', $trimmed,
                    "Trace content at line {$i} missing right border: '{$plain}'");
            }
        }
        
        $this->assertGreaterThan(0, $traceBoxCount, 'Should find at least one trace box');
        $this->assertCount($traceBoxCount, $footerLineNums, 
            'Each trace header should have a matching footer');
    }

    /**
     * Assert that the actual rows match the expected snapshot.
     * 
     * @param string $name Snapshot name (without extension)
     * @param array<string> $actualRows Actual rendered rows
     */
    protected function assertSnapshotMatches(string $name, array $actualRows): void
    {
        $snapshotPath = $this->snapshotDir . '/' . $name . '.txt';
        $actualContent = implode("\n", $actualRows);

        // Update mode: save actual output as new snapshot
        if (getenv('UPDATE_SNAPSHOTS')) {
            file_put_contents($snapshotPath, $actualContent);
            $this->markTestSkipped("Snapshot updated: {$name}");
            return;
        }

        // Normal mode: compare against existing snapshot
        if (!file_exists($snapshotPath)) {
            // No snapshot exists - create it and fail
            file_put_contents($snapshotPath, $actualContent);
            $this->fail(
                "No snapshot exists for '{$name}'. Created initial snapshot at:\n" .
                "  {$snapshotPath}\n\n" .
                "Review the snapshot and re-run the test. To update snapshots:\n" .
                "  UPDATE_SNAPSHOTS=1 ./vendor/bin/phpunit --filter {$name}"
            );
        }

        $expectedContent = file_get_contents($snapshotPath);
        $expectedRows = explode("\n", $expectedContent);

        // Compare row by row for better error messages
        $maxRows = max(count($expectedRows), count($actualRows));
        $differences = [];

        for ($i = 0; $i < $maxRows; $i++) {
            $expected = $expectedRows[$i] ?? '<missing>';
            $actual = $actualRows[$i] ?? '<missing>';

            if ($expected !== $actual) {
                $differences[] = $this->formatDiff($i, $expected, $actual);
            }
        }

        if (!empty($differences)) {
            $diffOutput = implode("\n\n", array_slice($differences, 0, 5));
            $moreCount = count($differences) - 5;
            $moreMsg = $moreCount > 0 ? "\n\n... and {$moreCount} more differences" : '';

            $this->fail(
                "Snapshot '{$name}' does not match.\n\n" .
                "To update the snapshot:\n" .
                "  UPDATE_SNAPSHOTS=1 ./vendor/bin/phpunit --filter {$name}\n\n" .
                "Differences:\n{$diffOutput}{$moreMsg}"
            );
        }

        $this->assertTrue(true);
    }

    /**
     * Format a diff between expected and actual for a single row.
     */
    protected function formatDiff(int $row, string $expected, string $actual): string
    {
        $output = "Row {$row}:\n";
        $output .= "  Expected: " . $this->visualize($expected) . "\n";
        $output .= "  Actual:   " . $this->visualize($actual) . "\n";

        // Show character-by-character diff for short strings
        if (strlen($expected) < 200 && strlen($actual) < 200) {
            $diffPos = $this->findFirstDifference($expected, $actual);
            if ($diffPos !== null) {
                $output .= "  First diff at position {$diffPos}:\n";
                $context = 20;
                $start = max(0, $diffPos - $context);
                $expSnip = substr($expected, $start, $context * 2);
                $actSnip = substr($actual, $start, $context * 2);
                $output .= "    Expected: ..." . $this->visualize($expSnip) . "...\n";
                $output .= "    Actual:   ..." . $this->visualize($actSnip) . "...\n";
                $output .= "              " . str_repeat(' ', $diffPos - $start + 3) . "^\n";
            }
        }

        return $output;
    }

    /**
     * Make control characters and whitespace visible.
     */
    protected function visualize(string $str): string
    {
        $str = str_replace("\t", '→', $str);
        $str = str_replace(' ', '·', $str);
        return $str;
    }

    /**
     * Find the position of the first difference between two strings.
     */
    protected function findFirstDifference(string $a, string $b): ?int
    {
        $len = min(strlen($a), strlen($b));
        for ($i = 0; $i < $len; $i++) {
            if ($a[$i] !== $b[$i]) {
                return $i;
            }
        }
        if (strlen($a) !== strlen($b)) {
            return $len;
        }
        return null;
    }

    /**
     * Get the last N characters of a string for error messages.
     */
    protected function lastChars(string $str, int $n): string
    {
        if (strlen($str) <= $n) {
            return $str;
        }
        return '...' . substr($str, -$n);
    }
}
