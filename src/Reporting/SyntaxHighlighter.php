<?php declare(strict_types = 1);

namespace ShipMonk\CopyPasteDetector\Reporting;

use LogicException;
use function array_key_exists;
use function extension_loaded;
use function in_array;
use function is_string;
use function sprintf;
use function str_replace;
use function str_starts_with;
use function strlen;
use function strtolower;
use function token_get_all;
use function trim;
use const T_ABSTRACT;
use const T_AS;
use const T_BREAK;
use const T_CALLABLE;
use const T_CASE;
use const T_CATCH;
use const T_CLASS;
use const T_CLONE;
use const T_COMMENT;
use const T_CONST;
use const T_CONSTANT_ENCAPSED_STRING;
use const T_CONTINUE;
use const T_DECLARE;
use const T_DEFAULT;
use const T_DNUMBER;
use const T_DO;
use const T_DOC_COMMENT;
use const T_ECHO;
use const T_ELSE;
use const T_ELSEIF;
use const T_EMPTY;
use const T_ENCAPSED_AND_WHITESPACE;
use const T_ENDDECLARE;
use const T_ENDFOR;
use const T_ENDFOREACH;
use const T_ENDIF;
use const T_ENDSWITCH;
use const T_ENDWHILE;
use const T_ENUM;
use const T_EXTENDS;
use const T_FINAL;
use const T_FINALLY;
use const T_FN;
use const T_FOR;
use const T_FOREACH;
use const T_FUNCTION;
use const T_GLOBAL;
use const T_GOTO;
use const T_IF;
use const T_IMPLEMENTS;
use const T_INCLUDE;
use const T_INCLUDE_ONCE;
use const T_INSTANCEOF;
use const T_INSTEADOF;
use const T_INTERFACE;
use const T_ISSET;
use const T_LIST;
use const T_LNUMBER;
use const T_MATCH;
use const T_NAMESPACE;
use const T_NEW;
use const T_OPEN_TAG;
use const T_PRINT;
use const T_PRIVATE;
use const T_PROTECTED;
use const T_PUBLIC;
use const T_READONLY;
use const T_REQUIRE;
use const T_REQUIRE_ONCE;
use const T_RETURN;
use const T_STATIC;
use const T_STRING;
use const T_SWITCH;
use const T_THROW;
use const T_TRAIT;
use const T_TRY;
use const T_UNSET;
use const T_USE;
use const T_VAR;
use const T_VARIABLE;
use const T_WHILE;
use const T_WHITESPACE;
use const T_YIELD;
use const T_YIELD_FROM;

/**
 * Syntax highlighter for PHP code using native tokenization and ANSI colors
 */
final class SyntaxHighlighter
{

    // ANSI color codes
    private const COLOR_RESET = "\033[0m";
    private const COLOR_KEYWORD = "\033[94m"; // Bright blue
    private const COLOR_STRING = "\033[32m"; // Green
    private const COLOR_VARIABLE = "\033[36m"; // Cyan
    private const COLOR_COMMENT = "\033[90m"; // Gray
    private const COLOR_NUMBER = "\033[33m"; // Yellow
    private const COLOR_TYPE = "\033[35m"; // Magenta
    private const COLOR_DIM = "\033[2m"; // Dim
    private const FORMAT_BOLD = "\033[1m"; // Bold
    private const DIFF_LINE_BG = "\033[48;5;235m"; // Dark gray background marking divergent-line rows
    private const DIFF_HIGHLIGHT = "\033[48;5;238m"; // Slightly lighter gray to make char-level diffs stand out inside DIFF_LINE_BG

    private bool $enabled;

    /**
     * @var array<int, string> Map of token types to color codes
     */
    private array $tokenColors = [];

    public function __construct(bool $enabled)
    {
        $this->enabled = $enabled && extension_loaded('tokenizer');
        $this->initializeTokenColors();
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Initialize token to color mapping
     */
    private function initializeTokenColors(): void
    {
        // Keywords
        $keywords = [
            T_ABSTRACT, T_AS, T_BREAK, T_CALLABLE, T_CASE, T_CATCH, T_CLASS, T_CLONE,
            T_CONST, T_CONTINUE, T_DECLARE, T_DEFAULT, T_DO, T_ECHO, T_ELSE, T_ELSEIF,
            T_EMPTY, T_ENDDECLARE, T_ENDFOR, T_ENDFOREACH, T_ENDIF, T_ENDSWITCH,
            T_ENDWHILE, T_EXTENDS, T_FINAL, T_FINALLY, T_FN, T_FOR, T_FOREACH, T_FUNCTION,
            T_GLOBAL, T_GOTO, T_IF, T_IMPLEMENTS, T_INCLUDE, T_INCLUDE_ONCE, T_INSTANCEOF,
            T_INSTEADOF, T_INTERFACE, T_ISSET, T_LIST, T_MATCH, T_NAMESPACE, T_NEW,
            T_PRINT, T_PRIVATE, T_PROTECTED, T_PUBLIC, T_READONLY, T_REQUIRE,
            T_REQUIRE_ONCE, T_RETURN, T_STATIC, T_SWITCH, T_THROW, T_TRAIT, T_TRY,
            T_UNSET, T_USE, T_VAR, T_WHILE, T_YIELD, T_YIELD_FROM, T_ENUM,
        ];

        foreach ($keywords as $keyword) {
            $this->tokenColors[$keyword] = self::COLOR_KEYWORD;
        }

        // Comments
        $this->tokenColors[T_COMMENT] = self::COLOR_COMMENT;
        $this->tokenColors[T_DOC_COMMENT] = self::COLOR_COMMENT;

        // Strings
        $this->tokenColors[T_CONSTANT_ENCAPSED_STRING] = self::COLOR_STRING;
        $this->tokenColors[T_ENCAPSED_AND_WHITESPACE] = self::COLOR_STRING;

        // Numbers
        $this->tokenColors[T_LNUMBER] = self::COLOR_NUMBER;
        $this->tokenColors[T_DNUMBER] = self::COLOR_NUMBER;

        // Variables
        $this->tokenColors[T_VARIABLE] = self::COLOR_VARIABLE;

        // Type hints (we'll handle these specially)
        // T_STRING can be a type hint or a function name, need context
    }

    /**
     * Highlight PHP code with ANSI colors using native tokenization
     */
    public function highlight(string $code): string
    {
        if (!$this->enabled) {
            return $code;
        }

        // Add PHP tags if not present (required for tokenization)
        $needsTag = !str_starts_with(trim($code), '<?');
        if ($needsTag) {
            $code = '<?php ' . $code;
        }

        $tokens = token_get_all($code);
        if ($tokens === []) {
            throw new LogicException('Failed to tokenize PHP code for syntax highlighting');
        }

        $result = '';

        foreach ($tokens as $i => $token) {
            if (is_string($token)) {
                // Simple token (single character like {, }, ;)
                $result .= $token;
                continue;
            }

            [$tokenType, $tokenText] = $token;

            // Skip PHP opening tag if we added it
            if ($needsTag && $tokenType === T_OPEN_TAG) {
                continue;
            }

            // Get color for this token
            $color = $this->getTokenColor($tokenType, $tokenText, $tokens, $i);

            if ($color !== null) {
                $result .= $color . $tokenText . self::COLOR_RESET;
            } else {
                $result .= $tokenText;
            }
        }

        return $result;
    }

    /**
     * Get color for a token based on its type and context
     *
     * @param array<int, array{int, string, int}|string> $tokens
     */
    private function getTokenColor(
        int $tokenType,
        string $tokenText,
        array $tokens,
        int $index,
    ): ?string
    {
        // Check if we have a direct color mapping
        if (isset($this->tokenColors[$tokenType])) {
            return $this->tokenColors[$tokenType];
        }

        // Special handling for T_STRING (could be type hint, class name, function name, etc.)
        if ($tokenType === T_STRING) {
            // Check if it's a type hint
            if ($this->isTypeHint($tokenText, $tokens, $index)) {
                return self::COLOR_TYPE;
            }
        }

        return null;
    }

    /**
     * Check if a T_STRING token is being used as a type hint
     *
     * @param array<int, array{int, string, int}|string> $tokens
     */
    private function isTypeHint(
        string $text,
        array $tokens,
        int $index,
    ): bool
    {
        // Common type hints
        $typeHints = [
            'int', 'float', 'string', 'bool', 'array', 'object', 'mixed', 'void',
            'null', 'true', 'false', 'self', 'parent', 'static', 'iterable', 'callable',
            'never', 'resource',
        ];

        $lowerText = strtolower($text);
        if (in_array($lowerText, $typeHints, true)) {
            return true;
        }

        // Check if preceded by colon (return type) or question mark (nullable type)
        for ($i = $index - 1; $i >= 0; $i--) {
            if (!array_key_exists($i, $tokens)) {
                break;
            }
            $prevTokenData = $tokens[$i];
            if (is_string($prevTokenData)) {
                if ($prevTokenData === ':' || $prevTokenData === '?') {
                    return true;
                }
                break;
            }
            $prevTokenType = $prevTokenData[0];
            if ($prevTokenType !== T_WHITESPACE) {
                break;
            }
        }

        return false;
    }

    /**
     * Format a file path with bold styling
     */
    public function formatPath(string $path): string
    {
        if (!$this->enabled) {
            return $path;
        }

        return self::FORMAT_BOLD . $path . self::COLOR_RESET;
    }

    /**
     * Format text with dim styling
     */
    public function formatDim(string $text): string
    {
        if (!$this->enabled) {
            return $text;
        }

        return self::COLOR_DIM . $text . self::COLOR_RESET;
    }

    /**
     * Format a line number right-aligned with dim styling
     */
    public function formatLineNumber(
        int $lineNumber,
        int $width,
    ): string
    {
        $formatted = sprintf('%' . $width . 'd', $lineNumber);
        return $this->formatDim($formatted);
    }

    /**
     * Highlight PHP code with ANSI colors and background highlighting for diff ranges
     *
     * @param list<array{int, int}> $diffRanges Array of [start, end] character positions to highlight
     */
    public function highlightWithDiffs(
        string $code,
        array $diffRanges,
    ): string
    {
        if (!$this->enabled || $diffRanges === []) {
            return $this->highlight($code);
        }

        // Add PHP tags if not present (required for tokenization)
        $needsTag = !str_starts_with(trim($code), '<?');
        if ($needsTag) {
            $code = '<?php ' . $code;
        }

        $tokens = token_get_all($code);
        if ($tokens === []) {
            throw new LogicException('Failed to tokenize PHP code for syntax highlighting');
        }

        $result = '';
        $currentPos = 0;

        foreach ($tokens as $i => $token) {
            if (is_string($token)) {
                $tokenText = $token;
                $tokenType = null;
            } else {
                [$tokenType, $tokenText] = $token;

                // Skip PHP opening tag if we added it
                if ($needsTag && $tokenType === T_OPEN_TAG) {
                    continue;
                }
            }

            $tokenLen = strlen($tokenText);

            // Get color for this token
            $color = null;
            if ($tokenType !== null) {
                $color = $this->getTokenColor($tokenType, $tokenText, $tokens, $i);
            }

            // Check if this token overlaps with any diff range
            $inDiffRange = $this->isInDiffRange($currentPos, $currentPos + $tokenLen, $diffRanges);

            if ($inDiffRange) {
                if ($color !== null) {
                    $result .= self::DIFF_HIGHLIGHT . $color . $tokenText . self::COLOR_RESET;
                } else {
                    $result .= self::DIFF_HIGHLIGHT . $tokenText . self::COLOR_RESET;
                }
            } elseif ($color !== null) {
                $result .= $color . $tokenText . self::COLOR_RESET;
            } else {
                $result .= $tokenText;
            }

            $currentPos += $tokenLen;
        }

        return $result;
    }

    /**
     * Check if a range overlaps with any diff range
     *
     * @param list<array{int, int}> $diffRanges
     */
    private function isInDiffRange(
        int $start,
        int $end,
        array $diffRanges,
    ): bool
    {
        foreach ($diffRanges as [$rangeStart, $rangeEnd]) {
            // Check for overlap
            if ($start < $rangeEnd && $end > $rangeStart) {
                return true;
            }
        }
        return false;
    }

    /**
     * Wrap an already-highlighted line in a background color marking it as a
     * divergent row. Internal color resets get a re-applied line background so
     * the band stays continuous across syntax-colored tokens (including over
     * char-level diff highlights, which use a slightly brighter shade).
     */
    public function applyDivergentLineBackground(string $highlightedLine): string
    {
        if (!$this->enabled) {
            return $highlightedLine;
        }
        $reapplied = str_replace(
            self::COLOR_RESET,
            self::COLOR_RESET . self::DIFF_LINE_BG,
            $highlightedLine,
        );
        return self::DIFF_LINE_BG . $reapplied . self::COLOR_RESET;
    }

}
