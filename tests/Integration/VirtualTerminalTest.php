<?php

namespace SoloTerm\Vtail\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SoloTerm\Vtail\Formatting\AnsiAware;

/**
 * Test harness using virtual terminal rendering.
 *
 * These tests use TestableApplication to render vtail output to a virtual
 * Screen buffer, allowing inspection of the actual rendered content.
 */
class VirtualTerminalTest extends TestCase
{
    protected function getFixturePath(string $name): string
    {
        return __DIR__.'/../Fixtures/'.$name;
    }

    #[Test]
    public function it_renders_basic_log_lines()
    {
        $app = new TestableApplication;
        $app->setDimensions(80, 24);

        $app->addLines([
            '[2025-01-01 12:00:00] local.INFO: Hello world',
            '[2025-01-01 12:00:01] local.INFO: Second message',
        ]);

        $output = $app->renderFrame();
        $plainRows = $app->getPlainRows();

        // Row 0 should be the status bar
        $this->assertStringContainsString('Lines: 2', $plainRows[0]);

        // Content should contain the log messages
        $plain = $app->getPlainOutput();
        $this->assertStringContainsString('Hello world', $plain);
        $this->assertStringContainsString('Second message', $plain);
    }

    #[Test]
    public function it_renders_exception_with_stack_trace()
    {
        $app = new TestableApplication;
        $app->setDimensions(120, 40);

        // Load the real fixture
        $app->loadFixture($this->getFixturePath('enhance-log-wrap-vendor-test.log'));

        $output = $app->renderFrame();
        $formatted = $app->getFormattedLines();

        // Should have formatted lines
        $this->assertNotEmpty($formatted);

        // Check for trace box elements in formatted lines (before Screen processing)
        // The trace header contains box-drawing characters
        $hasTraceBox = false;
        foreach ($formatted as $line) {
            $plain = AnsiAware::plain($line);
            // Look for trace box header or closing border
            if (str_contains($plain, '╭─Trace') || str_contains($plain, '╰═')) {
                $hasTraceBox = true;
                break;
            }
        }
        $this->assertTrue($hasTraceBox, 'Should have trace box elements in formatted output');
    }

    #[Test]
    public function vendor_toggle_reduces_visible_lines()
    {
        $app = new TestableApplication;
        $app->setDimensions(120, 40);
        $app->loadFixture($this->getFixturePath('enhance-log-wrap-vendor-test.log'));

        // Render with vendor shown (default)
        $app->renderFrame();
        $linesWithVendor = count($app->getFormattedLines());

        // Toggle vendor frames off
        $app->pressKey('v');
        $app->renderFrame();
        $linesWithoutVendor = count($app->getFormattedLines());

        // Should have fewer lines with vendor hidden
        $this->assertLessThan(
            $linesWithVendor,
            $linesWithoutVendor,
            "Hiding vendor should reduce line count. With: {$linesWithVendor}, Without: {$linesWithoutVendor}"
        );

        // Should have compressed markers
        $compressed = array_filter($app->getFormattedLines(), function ($line) {
            return str_contains($line, '#…');
        });
        $this->assertNotEmpty($compressed, 'Should have compressed vendor markers');
    }

    #[Test]
    public function wrap_toggle_changes_line_count()
    {
        $app = new TestableApplication;
        $app->setDimensions(80, 30); // Narrow width to force wrapping

        // Add long lines that will wrap
        $longLine = str_repeat('A', 200);
        $app->addLines([
            '[2025-01-01] '.$longLine,
            '[2025-01-01] Short line',
        ]);

        // With wrapping enabled (default)
        $app->renderFrame();
        $wrappedCount = count($app->getFormattedLines());

        // Toggle wrapping off
        $app->pressKey('w');
        $app->renderFrame();
        $truncatedCount = count($app->getFormattedLines());

        // Wrapped should have more lines
        $this->assertGreaterThanOrEqual(
            $truncatedCount,
            $wrappedCount,
            'Wrapped output should have >= lines than truncated'
        );
    }

    #[Test]
    public function scroll_position_updates_visible_lines()
    {
        $app = new TestableApplication;
        $app->setDimensions(80, 10); // Small height to force scrolling

        // Add many lines to force scrolling
        $lines = [];
        for ($i = 1; $i <= 50; $i++) {
            $lines[] = "[2025-01-01] Line number {$i}";
        }
        $app->addLines($lines);

        // Initial render (following mode - should be at bottom)
        $app->renderFrame();
        $initialScroll = $app->getScrollIndex();

        $visibleBefore = $app->getVisibleLines();

        // Scroll up
        $app->pressKey('k'); // or up arrow
        $app->renderFrame();

        $visibleAfter = $app->getVisibleLines();
        $scrollAfter = $app->getScrollIndex();

        // Scroll index should have changed
        $this->assertLessThan($initialScroll, $scrollAfter, 'Scroll should move up');

        // Following should be disabled
        $this->assertFalse($app->isFollowing(), 'Should no longer be following');
    }

    #[Test]
    public function status_bar_shows_correct_state()
    {
        $app = new TestableApplication('/tmp/mylog.log');
        $app->setDimensions(100, 20);

        $app->addLines(['Line 1', 'Line 2', 'Line 3']);
        $app->renderFrame();

        $plainRows = $app->getPlainRows();
        $statusBar = $plainRows[0];

        // Should show filename
        $this->assertStringContainsString('mylog.log', $statusBar);
        // Should show line count
        $this->assertStringContainsString('Lines: 3', $statusBar);
    }

    #[Test]
    public function debug_current_rendering_issue()
    {
        // This test is for debugging the actual rendering problem.
        // Run with: ./vendor/bin/phpunit --filter debug_current_rendering_issue
        // and inspect the output.

        $app = new TestableApplication;
        $app->setDimensions(120, 40);

        // Load fixture
        $app->loadFixture($this->getFixturePath('enhance-log-wrap-vendor-test.log'));

        // Process and render
        $output = $app->renderFrame();

        // Uncomment these to see debug output when running the test:
        // $app->debugFormattedLines();
        // $app->debugPlain();
        // $app->debugFrame();

        // Check structure of formatted output
        $formatted = $app->getFormattedLines();
        $this->assertNotEmpty($formatted);

        // Dump state for inspection
        // var_dump($app->dump());

        // Assert basic structure
        $plainOutput = $app->getPlainOutput();

        // Status bar at top
        $rows = $app->getPlainRows();
        $this->assertMatchesRegularExpression('/Lines:\s*\d+/', $rows[0], 'First row should be status bar');

        // Last rows should be hotkey bar
        $lastRow = end($rows);
        $this->assertStringContainsString('quit', $lastRow, 'Last row should contain hotkey bar');
    }

    #[Test]
    public function it_inspects_raw_vs_formatted_output()
    {
        // This test helps debug differences between raw and formatted lines

        $app = new TestableApplication;
        $app->setDimensions(100, 30);

        $lines = [
            '[2025-01-01 12:00:00] local.ERROR: Test error {"exception":"[object] (Exception: Test at /path/file.php:10)',
            '[stacktrace]',
            '#0 /app/vendor/laravel/framework/src/Test.php(50): method()',
            '#1 /app/src/MyClass.php(25): userMethod()',
            '#2 /app/vendor/other/package/File.php(100): otherMethod()',
            '"}',
        ];

        $app->addLines($lines);
        $app->renderFrame();

        $formatted = $app->getFormattedLines();

        // Debug: show what each line becomes
        foreach ($formatted as $i => $line) {
            $plain = AnsiAware::plain($line);
            // echo "Line $i: " . substr($plain, 0, 100) . "\n";
        }

        // Line 0 should be the exception header (error message)
        $this->assertStringContainsString('Test error', AnsiAware::plain($formatted[0]));

        // Should have the trace box header
        $hasTraceHeader = false;
        foreach ($formatted as $line) {
            if (str_contains(AnsiAware::plain($line), 'Trace')) {
                $hasTraceHeader = true;
                break;
            }
        }
        $this->assertTrue($hasTraceHeader, 'Should have trace header');
    }

    #[Test]
    public function vendor_frame_detection_works()
    {
        $app = new TestableApplication;
        $app->setDimensions(120, 40);

        // Stack frames need the ANSI reset prefix that LogFormatter expects
        // Real log output from Laravel has \e[0m prefix on stack frames
        // Using the same pattern as the real fixture: multiple consecutive vendor frames
        $lines = [
            '[2025-01-01] Error {"exception":"[object] (Exception at /app/file.php:1)',
            '[stacktrace]',
            "\e[0m#0 /app/vendor/laravel/framework/src/Pipeline.php(50): method()",  // vendor
            "\e[0m#1 /app/vendor/laravel/framework/src/Router.php(30): route()",     // vendor (consecutive)
            "\e[0m#2 /app/src/Controllers/MyController.php(25): handle()",            // not vendor
            "\e[0m#3 /app/vendor/symfony/http-foundation/Request.php(100): run()",   // vendor
            "\e[0m#4 /app/vendor/symfony/http-kernel/Kernel.php(80): kernel()",      // vendor (consecutive)
            "\e[0m#5 /app/src/Services/UserService.php(75): process()",              // not vendor
            "\e[0m#6 {main}",  // vendor (main is always considered vendor)
            '"}',
        ];

        $app->addLines($lines);
        $app->renderFrame();

        $formattedWithVendor = $app->getFormattedLines();
        $countWithVendor = count($formattedWithVendor);

        // Toggle vendor off
        $app->pressKey('v');
        $app->renderFrame();

        $formattedWithoutVendor = $app->getFormattedLines();
        $countWithoutVendor = count($formattedWithoutVendor);

        // Debug: show what we have
        // echo "\n--- With Vendor ({$countWithVendor} lines) ---\n";
        // foreach ($formattedWithVendor as $i => $line) {
        //     echo "$i: " . substr(AnsiAware::plain($line), 0, 80) . "\n";
        // }
        // echo "\n--- Without Vendor ({$countWithoutVendor} lines) ---\n";
        // foreach ($formattedWithoutVendor as $i => $line) {
        //     echo "$i: " . substr(AnsiAware::plain($line), 0, 80) . "\n";
        // }

        // With vendor hidden, consecutive vendor frames get collapsed
        // So we should have fewer lines
        $this->assertLessThan(
            $countWithVendor,
            $countWithoutVendor,
            "Vendor hidden should have fewer lines. With vendor: {$countWithVendor}, Without: {$countWithoutVendor}"
        );

        // Non-vendor frames should still be visible
        $plainOutput = $app->getPlainOutput();
        $this->assertStringContainsString('MyController', $plainOutput, 'Non-vendor controller should be visible');
        $this->assertStringContainsString('UserService', $plainOutput, 'Non-vendor service should be visible');

        // Vendor frames should be collapsed (shown as #…)
        $this->assertStringContainsString('#…', $plainOutput, 'Should have collapsed vendor marker');
    }

    #[Test]
    public function debug_vendor_frame_formatting()
    {
        // Debug test to understand vendor frame detection
        // Run with: ./vendor/bin/phpunit --filter debug_vendor_frame_formatting -v

        $app = new TestableApplication;
        $app->setDimensions(120, 30);

        // Minimal stack trace - must match the exact format LogFormatter expects
        // Stack frames from real Laravel logs have \e[0m prefix
        $lines = [
            '[2025-01-01] Error {"exception":"[object] (Exception at /file.php:1)',
            '[stacktrace]',
            "\e[0m#0 /vendor/laravel/Pipeline.php(50): vendor_method()",
            "\e[0m#1 /src/MyController.php(25): user_method()",
            "\e[0m#2 /vendor/other/File.php(100): other_vendor()",
            '"}',
        ];

        $app->addLines($lines);
        $app->renderFrame();

        // Debug: examine each formatted line
        $formatted = $app->getFormattedLines();

        // Count vendor frames by looking at the content
        $vendorCount = 0;
        foreach ($formatted as $i => $line) {
            $plain = AnsiAware::plain($line);
            // Vendor frames are either compressed (#…) or contain /vendor/
            if (str_contains($plain, '/vendor/') || str_contains($plain, '{main}')) {
                $vendorCount++;
            }
        }

        // We should detect some vendor frames in the output
        $this->assertGreaterThan(0, $vendorCount, 'Should have vendor frames in output');
        $this->assertNotEmpty($formatted);
    }

    #[Test]
    public function vendor_frame_detection_with_real_fixture()
    {
        // Use the real fixture which has known vendor frames
        $app = new TestableApplication;
        $app->setDimensions(120, 50);

        // Load the first 50 lines of the fixture (contains stack trace)
        $fixturePath = $this->getFixturePath('enhance-log-wrap-vendor-test.log');
        $allLines = file($fixturePath, FILE_IGNORE_NEW_LINES);
        $testLines = array_slice($allLines, 0, 50);

        $app->addLines($testLines);
        $app->renderFrame();

        $countWithVendor = count($app->getFormattedLines());

        // Toggle vendor off
        $app->pressKey('v');
        $app->renderFrame();

        $countWithoutVendor = count($app->getFormattedLines());

        // The fixture has many vendor frames - hiding them should reduce line count
        $this->assertLessThan(
            $countWithVendor,
            $countWithoutVendor,
            "Real fixture: With vendor: {$countWithVendor}, Without: {$countWithoutVendor}"
        );
    }
}
