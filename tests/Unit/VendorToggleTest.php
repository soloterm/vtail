<?php

namespace SoloTerm\Vtail\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SoloTerm\Vtail\Formatting\LogFormatter;

class VendorToggleTest extends TestCase
{
    #[Test]
    public function it_can_toggle_vendor_frames_on_realistic_log()
    {
        $formatter = new LogFormatter(120);

        // Simulate raw log lines from tail -f (these are the raw lines, no ANSI prefixes)
        $rawLines = [
            '[stacktrace]',
            '#0 [internal function]: SomeClass->closure(Array)',
            '#1 /Users/aaron/Code/fusioncast/storage/file.php(33): call_user_func()',
            '#2 /Users/aaron/Code/duo/src/FusionPage.php(112): favorite()',
            '#3 /Users/aaron/Code/fusioncast/vendor/laravel/framework/src/Illuminate/Routing/ControllerDispatcher.php(44): callAction()',
            '#4 /Users/aaron/Code/fusioncast/vendor/laravel/framework/src/Illuminate/Routing/Route.php(266): dispatch()',
            '#5 /Users/aaron/Code/fusioncast/vendor/laravel/framework/src/Illuminate/Routing/Route.php(212): runController()',
            '#6 /Users/aaron/Code/duo/src/Http/Request/RunsSyntheticActions.php(111): run()',
            '#7 /Users/aaron/Code/fusioncast/vendor/laravel/framework/src/Illuminate/Pipeline/Pipeline.php(170): closure()',
            '#8 {main}',
        ];

        // Format lines into collection
        $collection = $formatter->formatLines($rawLines);

        // Get display count with hideVendor = false (show all)
        $collection->setHideVendor(false);
        $shownCount = $collection->getDisplayLineCount();

        // Get display count with hideVendor = true (hide vendor)
        $collection->setHideVendor(true);
        $hiddenCount = $collection->getDisplayLineCount();

        // The hidden version should have fewer lines
        $this->assertLessThan($shownCount, $hiddenCount, 'Hidden vendor should have fewer lines');

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
        $shownCountAgain = $collection->getDisplayLineCount();

        $this->assertEquals(
            $shownCount,
            $shownCountAgain,
            'Re-showing vendor should return to original line count'
        );
    }

    #[Test]
    public function vendor_detection_works_on_raw_lines()
    {
        $formatter = new LogFormatter(120);

        // Raw lines without ANSI codes
        $this->assertTrue(
            $formatter->isVendorFrame('#3 /path/vendor/laravel/src/File.php(123): method()'),
            'Should detect vendor in raw line'
        );

        $this->assertFalse(
            $formatter->isVendorFrame('#2 /path/app/Controllers/HomeController.php(45): index()'),
            'Should not detect vendor in app line'
        );

        $this->assertTrue(
            $formatter->isVendorFrame('#99 {main}'),
            'Should detect {main} as vendor'
        );
    }

    #[Test]
    public function stack_trace_pattern_matches_raw_lines()
    {
        // The pattern used in formatLine to detect stack trace lines
        $pattern = '/#[0-9]+ /';

        // Raw lines without ANSI prefix
        $this->assertEquals(1, preg_match($pattern, '#0 [internal function]'));
        $this->assertEquals(1, preg_match($pattern, '#3 /path/to/file.php(123)'));
        $this->assertEquals(1, preg_match($pattern, '#99 {main}'));

        // Lines with ANSI prefix (from Solo's GNU Screen wrapper)
        $this->assertEquals(1, preg_match($pattern, "\e[0m#3 /path/to/file.php(123)"));

        // Non-matching lines
        $this->assertEquals(0, preg_match($pattern, '[stacktrace]'));
        $this->assertEquals(0, preg_match($pattern, 'Normal log line'));
    }
}
