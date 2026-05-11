<?php declare(strict_types = 1);

namespace ShipMonk\CopyPasteDetectorTests\Reporting;

use PHPUnit\Framework\TestCase;
use ShipMonk\CopyPasteDetector\Reporting\SyntaxHighlighter;
use function strlen;

final class SyntaxHighlighterTest extends TestCase
{

    public function testHighlightWithColorsEnabled(): void
    {
        $highlighter = new SyntaxHighlighter(true);
        $code = 'function foo($bar) { return $bar + 1; }';
        $highlighted = $highlighter->highlight($code);

        // Should contain ANSI color codes when enabled (assuming tokenizer extension is loaded)
        self::assertStringContainsString("\033[", $highlighted, 'Should contain ANSI color codes');
        self::assertNotSame($code, $highlighted, 'Should modify the code');
    }

    public function testHighlightWithColorsDisabled(): void
    {
        $highlighter = new SyntaxHighlighter(false);
        $code = 'function foo($bar) { return $bar + 1; }';
        $highlighted = $highlighter->highlight($code);

        // Should not contain ANSI color codes
        self::assertStringNotContainsString("\033[", $highlighted, 'Should not contain ANSI color codes');
        self::assertSame($code, $highlighted, 'Should return original code unchanged');
    }

    public function testHighlightKeywords(): void
    {
        $highlighter = new SyntaxHighlighter(true);
        $code = 'if (true) return false;';
        $highlighted = $highlighter->highlight($code);

        // Keywords like 'if', 'return', 'true', 'false' should be highlighted
        self::assertNotSame($code, $highlighted, 'Should modify the code with highlighting');
    }

    public function testHighlightVariables(): void
    {
        $highlighter = new SyntaxHighlighter(true);
        $code = '$foo = $bar;';
        $highlighted = $highlighter->highlight($code);

        // Variables should be highlighted
        self::assertNotSame($code, $highlighted, 'Should highlight variables');
    }

    public function testHighlightStrings(): void
    {
        $highlighter = new SyntaxHighlighter(true);
        $code = '$str = "hello world";';
        $highlighted = $highlighter->highlight($code);

        // Strings should be highlighted
        self::assertNotSame($code, $highlighted, 'Should highlight strings');
    }

    public function testHighlightNumbers(): void
    {
        $highlighter = new SyntaxHighlighter(true);
        $code = '$num = 42;';
        $highlighted = $highlighter->highlight($code);

        // Numbers should be highlighted
        self::assertNotSame($code, $highlighted, 'Should highlight numbers');
    }

    public function testHighlightWithDiffsFallsBackToHighlightWhenDisabled(): void
    {
        $highlighter = new SyntaxHighlighter(false);
        $code = '$foo = 1;';
        self::assertSame($code, $highlighter->highlightWithDiffs($code, [[0, 4]]));
    }

    public function testHighlightWithDiffsFallsBackToHighlightWhenNoRanges(): void
    {
        $highlighter = new SyntaxHighlighter(true);
        $code = '$foo = 1;';
        self::assertSame(
            $highlighter->highlight($code),
            $highlighter->highlightWithDiffs($code, []),
        );
    }

    public function testHighlightWithDiffsWrapsRangesWithDiffHighlight(): void
    {
        $highlighter = new SyntaxHighlighter(true);
        $code = '$foo = 42;';

        // Highlight the variable ($foo, positions 0..4)
        $result = $highlighter->highlightWithDiffs($code, [[0, 4]]);

        // Should contain ANSI escape codes (both color and the diff background)
        self::assertStringContainsString("\033[", $result);
        // Variable text must be present
        self::assertStringContainsString('$foo', $result);
        // Numeric literal sits outside the diff range, so it should still be present
        self::assertStringContainsString('42', $result);
    }

    public function testHighlightWithDiffsHandlesCodeWithoutOpeningTag(): void
    {
        $highlighter = new SyntaxHighlighter(true);
        // No <?php prefix - method should add it for tokenization but strip it from output
        $code = 'return $x + 1;';

        $result = $highlighter->highlightWithDiffs($code, [[0, strlen($code)]]);

        self::assertStringNotContainsString('<?php', $result);
        self::assertStringContainsString('return', $result);
        self::assertStringContainsString('$x', $result);
    }

    public function testHighlightWithDiffsHandlesCodeWithOpeningTag(): void
    {
        $highlighter = new SyntaxHighlighter(true);
        $code = '<?php $x = 1;';

        $result = $highlighter->highlightWithDiffs($code, [[6, 8]]);

        // Opening tag is in input, should appear in output
        self::assertStringContainsString('<?php', $result);
        self::assertStringContainsString('$x', $result);
    }

}
