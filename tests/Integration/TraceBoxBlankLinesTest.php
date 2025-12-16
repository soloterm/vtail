<?php

namespace SoloTerm\Vtail\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SoloTerm\Vtail\Formatting\AnsiAware;

/**
 * Tests for trace box rendering - ensures no blank lines appear between stack frames
 * and that word wrapping doesn't break words with extra spaces.
 */
class TraceBoxBlankLinesTest extends TestCase
{
    #[Test]
    public function no_blank_lines_inside_trace_box()
    {
        $app = new TestableApplication;
        $app->setDimensions(120, 40);

        $app->loadFixture(__DIR__.'/../Fixtures/lifeos-realistic.log');
        $app->renderFrame();

        $formatted = $app->getFormattedLines();

        $inTraceBox = false;
        $blankLineIndices = [];

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
                // Check if this is a blank line (just borders with whitespace)
                $trimmedContent = trim(str_replace(['│', ' '], '', $plain));
                if ($trimmedContent === '') {
                    $blankLineIndices[] = $i;
                }
            }
        }

        $this->assertEmpty($blankLineIndices,
            'Found blank lines inside trace box at indices: '.implode(', ', $blankLineIndices));
    }

    #[Test]
    public function wrapped_continuation_lines_do_not_break_words()
    {
        $formatter = new \SoloTerm\Vtail\Formatting\LogFormatter(120);

        // Long line that will be wrapped mid-word
        $line = '#1 /vendor/symfony/console/Application.php(195): Symfony\\Component\\Console\\Application->doRun(Object(Symfony\\Component\\Console\\Input\\ArgvInput), Object(Symfony\\Component\\Console\\Output\\ConsoleOutput))';

        $wrapped = $formatter->wrapLine($line, 114, 4);

        // Join wrapped lines and verify no spaces inserted where words break
        $joined = implode('', $wrapped);

        // These patterns would appear if space was incorrectly added at wrap points
        $this->assertStringNotContainsString('oleO utput', $joined);
        $this->assertStringNotContainsString('Cons oleOutput', $joined);
    }

    #[Test]
    public function formatted_lines_do_not_exceed_terminal_width()
    {
        $app = new TestableApplication;
        $width = 120;
        $app->setDimensions($width, 40);

        $lines = file(__DIR__.'/../Fixtures/lifeos-realistic.log', FILE_IGNORE_NEW_LINES);
        $app->addLines(array_slice($lines, 55)); // Second exception
        $app->renderFrame();

        foreach ($app->getFormattedLines() as $i => $line) {
            $plainLen = AnsiAware::mb_strlen($line);
            $this->assertLessThanOrEqual($width, $plainLen,
                "Line $i exceeds terminal width ($plainLen > $width)");
        }
    }
}
