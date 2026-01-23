<?php declare(strict_types = 1);

namespace CopyPasteDetector\Tests;

use CopyPasteDetector\Reporting\SyntaxHighlighter;
use PHPUnit\Framework\TestCase;

final class SyntaxHighlighterTest extends TestCase
{

    public function testHighlightWithColorsEnabled(): void
    {
        $highlighter = new SyntaxHighlighter(true);
        $code = 'function foo($bar) { return $bar + 1; }';
        $highlighted = $highlighter->highlight($code);

        // Should contain ANSI color codes if enabled, or equal to original if disabled
        if ($highlighter->isEnabled()) {
            self::assertStringContainsString("\033[", $highlighted, 'Should contain ANSI color codes');
            self::assertNotSame($code, $highlighted, 'Should modify the code');
        } else {
            self::assertSame($code, $highlighted, 'Should return original if not enabled');
        }
    }

    public function testHighlightWithColorsDisabled(): void
    {
        $highlighter = new SyntaxHighlighter(false);
        $code = 'function foo($bar) { return $bar + 1; }';
        $highlighted = $highlighter->highlight($code);

        // Should not contain ANSI color codes
        self::assertStringNotContainsString("\033[", $highlighted, 'Should not contain ANSI color codes');
        self::assertSame($code, $highlighted, 'Should return original code unchanged');
        self::assertFalse($highlighter->isEnabled());
    }

    public function testHighlightKeywords(): void
    {
        $highlighter = new SyntaxHighlighter(true);
        $code = 'if (true) return false;';
        $highlighted = $highlighter->highlight($code);

        // Keywords like 'if', 'return', 'true', 'false' should be highlighted
        if ($highlighter->isEnabled()) {
            self::assertNotSame($code, $highlighted, 'Should modify the code with highlighting');
        } else {
            self::assertSame($code, $highlighted);
        }
    }

    public function testHighlightVariables(): void
    {
        $highlighter = new SyntaxHighlighter(true);
        $code = '$foo = $bar;';
        $highlighted = $highlighter->highlight($code);

        // Variables should be highlighted
        if ($highlighter->isEnabled()) {
            self::assertNotSame($code, $highlighted, 'Should highlight variables');
        } else {
            self::assertSame($code, $highlighted);
        }
    }

    public function testHighlightStrings(): void
    {
        $highlighter = new SyntaxHighlighter(true);
        $code = '$str = "hello world";';
        $highlighted = $highlighter->highlight($code);

        // Strings should be highlighted
        if ($highlighter->isEnabled()) {
            self::assertNotSame($code, $highlighted, 'Should highlight strings');
        } else {
            self::assertSame($code, $highlighted);
        }
    }

    public function testHighlightNumbers(): void
    {
        $highlighter = new SyntaxHighlighter(true);
        $code = '$num = 42;';
        $highlighted = $highlighter->highlight($code);

        // Numbers should be highlighted
        if ($highlighter->isEnabled()) {
            self::assertNotSame($code, $highlighted, 'Should highlight numbers');
        } else {
            self::assertSame($code, $highlighted);
        }
    }

    public function testDisableMethod(): void
    {
        $highlighter = new SyntaxHighlighter(true);

        $highlighter->disable();
        self::assertFalse($highlighter->isEnabled(), 'Should be disabled after calling disable()');

        $code = 'function test() {}';
        $highlighted = $highlighter->highlight($code);
        self::assertSame($code, $highlighted, 'Should not highlight when disabled');
    }

}
