<?php

namespace SoloTerm\Vtail\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SoloTerm\Vtail\Formatting\AnsiAware;
use SoloTerm\Vtail\Formatting\LogFormatter;

/**
 * Diagnostic test to identify blank lines in trace box rendering.
 */
class DiagnosticTest extends TestCase
{
    #[Test]
    public function diagnose_blank_lines_in_trace_box()
    {
        // Use exact width from screenshot analysis (~180 cols based on content)
        $terminalWidth = 180;

        $formatter = new LogFormatter($terminalWidth);

        // Load last 100 lines from actual log
        $logPath = '/Users/aaron/Code/lifeos/storage/logs/laravel.log';
        if (!file_exists($logPath)) {
            $this->markTestSkipped('Log file not found');
        }

        $lines = file($logPath, FILE_IGNORE_NEW_LINES);
        $lines = array_slice($lines, -100);

        $formatted = [];
        foreach ($lines as $index => $line) {
            $lineObj = $formatter->formatLine($line, $index);
            if ($lineObj === null) {
                continue;
            }
            foreach ($lineObj->formattedLines as $l) {
                $formatted[] = $l;
            }
        }

        // Find blank lines inside trace boxes
        $inTrace = false;
        $blankLines = [];
        $prevLine = null;

        foreach ($formatted as $i => $line) {
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
                // Check for blank content inside borders
                // A blank line would be: " │ " + spaces + " │ "
                $content = preg_replace('/^\s*│\s*/', '', $plain);
                $content = preg_replace('/\s*│\s*$/', '', $content);
                $content = trim($content);

                if ($content === '') {
                    $blankLines[] = [
                        'index' => $i,
                        'raw' => $line,
                        'plain' => $plain,
                        'prev_plain' => $prevLine ? AnsiAware::plain($prevLine) : null,
                    ];
                }
            }

            $prevLine = $line;
        }

        if (!empty($blankLines)) {
            echo "\n\n=== BLANK LINES FOUND ===\n";
            foreach ($blankLines as $info) {
                echo "Line {$info['index']}:\n";
                echo "  Plain: |{$info['plain']}|\n";
                echo "  After: |{$info['prev_plain']}|\n";
                echo "  Raw bytes: ".bin2hex(substr($info['raw'], 0, 50))."...\n\n";
            }
        }

        $this->assertEmpty($blankLines,
            'Found '.count($blankLines).' blank lines inside trace box');
    }

    #[Test]
    public function diagnose_wrapLine_produces_empty_continuation()
    {
        $formatter = new LogFormatter(180);

        // Test lines that might produce empty continuations
        $testLines = [
            "#47 /vendor/laravel/framework/src/Illuminate/Foundation/Http/Kernel.php(144): Illuminate\\Foundation\\Http\\Kernel->sendRequestThroughRouter(Object(Illuminate\\Http\\Request))",
            "#48 /vendor/laravel/framework/src/Illuminate/Foundation/Application.php(1220): Illuminate\\Foundation\\Http\\Kernel->handle(Object(Illuminate\\Http\\Request))",
        ];

        $traceContentWidth = 180 - 6; // Account for borders

        foreach ($testLines as $testLine) {
            echo "\n=== Testing line ===\n";
            echo "Input: $testLine\n\n";

            $wrapped = $formatter->wrapLine($testLine, $traceContentWidth, 4);

            echo 'Wrapped into '.count($wrapped)." lines:\n";
            foreach ($wrapped as $i => $line) {
                $plain = AnsiAware::plain($line);
                $isEmpty = trim($plain) === '';
                echo '  ['.$i.'] '.($isEmpty ? 'EMPTY!' : 'ok')." |$plain|\n";

                if ($isEmpty) {
                    echo '      Raw: '.bin2hex($line)."\n";
                }
            }
        }

        // Now test with formatLine which adds borders
        echo "\n=== Testing formatLine ===\n";
        foreach ($testLines as $index => $testLine) {
            // Prepend ANSI reset that Laravel logs have
            $line = "\e[0m".$testLine;
            $lineObj = $formatter->formatLine($line, $index);

            if ($lineObj !== null) {
                foreach ($lineObj->formattedLines as $i => $formattedLine) {
                    $plain = AnsiAware::plain($formattedLine);
                    $content = trim(str_replace(['│', ' '], '', $plain));
                    $isEmpty = $content === '';
                    echo '['.$i.'] '.($isEmpty ? 'BLANK!' : 'ok   ')." |$plain|\n";
                }
            }
        }

        $this->assertTrue(true); // Diagnostic test
    }

    #[Test]
    public function diagnose_exact_byte_output()
    {
        $formatter = new LogFormatter(180);

        // This specific line from the screenshot has issues after it
        $line = "\e[0m#47 /vendor/laravel/framework/src/Illuminate/Foundation/Http/Kernel.php(144): Illuminate\\Foundation\\Http\\Kernel->sendRequestThroughRouter(Object(Illuminate\\Http\\Request))";

        $lineObj = $formatter->formatLine($line, 0);

        echo "\n=== Byte-level analysis ===\n";
        echo 'Input length: '.strlen($line)."\n";
        echo 'Result type: '.($lineObj !== null ? 'Line('.count($lineObj->formattedLines).')' : 'null')."\n\n";

        if ($lineObj !== null) {
            foreach ($lineObj->formattedLines as $i => $r) {
                $plain = AnsiAware::plain($r);
                $visibleLen = AnsiAware::mb_strlen($r);
                echo "Line $i:\n";
                echo "  Visible length: $visibleLen\n";
                echo "  Plain: |$plain|\n";

                // Check if there are any invisible characters
                $printable = preg_replace('/[\x00-\x1f\x7f]/', '', $plain);
                if ($printable !== $plain) {
                    echo "  WARNING: Contains control characters!\n";
                    echo '  Hex: '.bin2hex($plain)."\n";
                }
            }
        }

        $this->assertTrue(true);
    }
}
