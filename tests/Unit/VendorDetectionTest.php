<?php

namespace SoloTerm\Vtail\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SoloTerm\Vtail\Formatting\LogFormatter;

class VendorDetectionTest extends TestCase
{
    protected LogFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new LogFormatter(120);
    }

    #[Test]
    public function detects_vendor_frame_in_plain_text()
    {
        $this->assertTrue(
            $this->formatter->isVendorFrame('#3 /path/to/vendor/laravel/framework/File.php(123): method()')
        );
    }

    #[Test]
    public function detects_vendor_frame_with_ansi_codes()
    {
        // Line with ANSI styling (as it would appear after highlightFileOnly)
        $styledLine = "\033[2m#03\033[0m/path/to/vendor/laravel/framework/File.php\033[2m(123): method()\033[0m";

        $this->assertTrue(
            $this->formatter->isVendorFrame($styledLine),
            'Should detect vendor in ANSI-styled line'
        );
    }

    #[Test]
    public function detects_main_frame_in_plain_text()
    {
        $this->assertTrue(
            $this->formatter->isVendorFrame('#67 {main}')
        );
    }

    #[Test]
    public function detects_main_frame_with_ansi_codes()
    {
        // {main} with ANSI codes at end (as it appears after highlightFileOnly)
        $styledMain = "\033[2m#67\033[0m{main}\033[2m\033[0m";

        $this->assertTrue(
            $this->formatter->isVendorFrame($styledMain),
            'Should detect {main} even with trailing ANSI codes'
        );
    }

    #[Test]
    public function does_not_detect_app_frame_as_vendor()
    {
        $this->assertFalse(
            $this->formatter->isVendorFrame('#2 /path/to/app/Http/Controllers/HomeController.php(45): index()')
        );
    }

    #[Test]
    public function does_not_detect_app_frame_with_ansi_as_vendor()
    {
        $styledApp = "\033[2m#02\033[0m/path/to/app/Http/Controllers/HomeController.php\033[2m(45): index()\033[0m";

        $this->assertFalse(
            $this->formatter->isVendorFrame($styledApp),
            'Should not detect app code as vendor'
        );
    }

    #[Test]
    public function bound_method_app_call_is_not_vendor()
    {
        // Special case: BoundMethod.php calling App code should not be vendor
        $line = '#5 /path/vendor/laravel/framework/src/Illuminate/Container/BoundMethod.php(36): App\\Http\\Controllers\\HomeController->index()';

        $this->assertFalse(
            $this->formatter->isVendorFrame($line),
            'BoundMethod calling App code should not be marked as vendor'
        );
    }

    #[Test]
    public function bound_method_non_app_call_is_vendor()
    {
        // BoundMethod calling non-App code IS vendor
        $line = '#5 /path/vendor/laravel/framework/src/Illuminate/Container/BoundMethod.php(36): Illuminate\\Something->handle()';

        $this->assertTrue(
            $this->formatter->isVendorFrame($line),
            'BoundMethod calling framework code should be marked as vendor'
        );
    }
}
