<?php declare(strict_types = 1);

namespace ShipMonk\CopyPasteDetectorTests\Reporting;

use PHPUnit\Framework\TestCase;
use ShipMonk\CopyPasteDetector\Reporting\SyntaxHighlighter;

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

}
