<?php

namespace SoloTerm\Vtail\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use SoloTerm\Vtail\Application;
use SoloTerm\Vtail\Formatting\LineCollection;
use SoloTerm\Vtail\Formatting\LogFormatter;
use SoloTerm\Vtail\Terminal\Terminal;

/**
 * Tests for the dirty flag optimization that prevents unnecessary re-renders.
 */
class DirtyFlagTest extends TestCase
{
    private function createApplication(): Application
    {
        $app = new Application(
            file: '/tmp/test.log',
            hideVendor: false,
            wrapLines: true
        );

        // Set up required dependencies so methods don't fail
        $this->setProperty($app, 'formatter', new LogFormatter(120));
        $this->setProperty($app, 'terminal', new class extends Terminal
        {
            public function cols(): int
            {
                return 120;
            }

            public function lines(): int
            {
                return 40;
            }
        });

        return $app;
    }

    private function createLineCollection(int $lineCount): LineCollection
    {
        $formatter = new LogFormatter(120);
        $rawLines = array_fill(0, $lineCount, 'test line content');

        return $formatter->formatLines($rawLines);
    }

    private function getProperty(Application $app, string $property): mixed
    {
        $reflection = new ReflectionProperty(Application::class, $property);
        $reflection->setAccessible(true);

        return $reflection->getValue($app);
    }

    private function setProperty(Application $app, string $property, mixed $value): void
    {
        $reflection = new ReflectionProperty(Application::class, $property);
        $reflection->setAccessible(true);
        $reflection->setValue($app, $value);
    }

    private function callMethod(Application $app, string $method, array $args = []): mixed
    {
        $reflection = new ReflectionClass(Application::class);
        $method = $reflection->getMethod($method);
        $method->setAccessible(true);

        return $method->invokeArgs($app, $args);
    }

    #[Test]
    public function application_starts_with_dirty_flag_true()
    {
        $app = new Application('/tmp/test.log');
        $this->assertTrue($this->getProperty($app, 'dirty'));
    }

    #[Test]
    public function scroll_up_sets_dirty_flag()
    {
        $app = $this->createApplication();
        $this->setProperty($app, 'dirty', false);
        $this->setProperty($app, 'lineCollection', $this->createLineCollection(100));

        $this->callMethod($app, 'scrollUp', [1]);

        $this->assertTrue($this->getProperty($app, 'dirty'));
    }

    #[Test]
    public function scroll_down_sets_dirty_flag()
    {
        $app = $this->createApplication();
        $this->setProperty($app, 'dirty', false);
        $this->setProperty($app, 'lineCollection', $this->createLineCollection(100));

        $this->callMethod($app, 'scrollDown', [1]);

        $this->assertTrue($this->getProperty($app, 'dirty'));
    }

    #[Test]
    public function toggle_follow_sets_dirty_flag()
    {
        $app = $this->createApplication();
        $this->setProperty($app, 'dirty', false);
        $this->setProperty($app, 'lineCollection', $this->createLineCollection(100));

        $this->callMethod($app, 'toggleFollow');

        $this->assertTrue($this->getProperty($app, 'dirty'));
    }

    #[Test]
    public function toggle_vendor_frames_sets_dirty_flag()
    {
        $app = $this->createApplication();
        $this->setProperty($app, 'dirty', false);
        $this->setProperty($app, 'lineCollection', $this->createLineCollection(10));

        $this->callMethod($app, 'toggleVendorFrames');

        $this->assertTrue($this->getProperty($app, 'dirty'));
    }

    #[Test]
    public function toggle_wrapping_sets_dirty_flag()
    {
        $app = $this->createApplication();
        $this->setProperty($app, 'dirty', false);
        $this->setProperty($app, 'lineCollection', $this->createLineCollection(10));

        $this->callMethod($app, 'toggleWrapping');

        $this->assertTrue($this->getProperty($app, 'dirty'));
    }

    #[Test]
    public function truncate_file_sets_dirty_flag()
    {
        // Create a temp file
        $tempFile = tempnam(sys_get_temp_dir(), 'vtail_test_');
        file_put_contents($tempFile, "test content\n");

        try {
            $app = new Application(
                file: $tempFile,
                hideVendor: false,
                wrapLines: true
            );

            // Set up required dependencies
            $this->setProperty($app, 'formatter', new LogFormatter(120));
            $this->setProperty($app, 'terminal', new class extends Terminal
            {
                public function cols(): int
                {
                    return 120;
                }

                public function lines(): int
                {
                    return 40;
                }
            });
            $this->setProperty($app, 'dirty', false);

            $this->callMethod($app, 'truncateFile');

            $this->assertTrue($this->getProperty($app, 'dirty'));
            $this->assertEquals('', file_get_contents($tempFile));
        } finally {
            unlink($tempFile);
        }
    }

    #[Test]
    public function collect_output_returns_true_when_new_output()
    {
        $app = $this->createApplication();

        // Set up mock pipes with data
        $pipes = [
            1 => fopen('php://memory', 'r+'),
        ];
        fwrite($pipes[1], "new log line\n");
        rewind($pipes[1]);

        $this->setProperty($app, 'tailPipes', $pipes);

        $result = $this->callMethod($app, 'collectOutput');

        $this->assertTrue($result, 'Should return true when new output received');

        fclose($pipes[1]);
    }

    #[Test]
    public function collect_output_returns_false_when_no_output()
    {
        $app = $this->createApplication();

        // Set up mock pipes with no data
        $pipes = [
            1 => fopen('php://memory', 'r+'),
        ];
        // Don't write anything

        $this->setProperty($app, 'tailPipes', $pipes);

        $result = $this->callMethod($app, 'collectOutput');

        $this->assertFalse($result, 'Should return false when no new output');

        fclose($pipes[1]);
    }
}
