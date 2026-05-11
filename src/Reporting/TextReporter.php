<?php declare(strict_types = 1);

namespace ShipMonk\CopyPasteDetector\Reporting;

use LogicException;
use ShipMonk\CopyPasteDetector\AST\Subtree;
use ShipMonk\CopyPasteDetector\Detection\CloneGroup;
use function array_key_exists;
use function array_slice;
use function count;
use function explode;
use function file;
use function getcwd;
use function implode;
use function realpath;
use function rtrim;
use function sprintf;
use function str_repeat;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;
use function trim;
use const DIRECTORY_SEPARATOR;
use const FILE_IGNORE_NEW_LINES;

/**
 * Reports clone detection results in human-readable text format
 */
final class TextReporter
{

    private readonly string $basePath;

    public function __construct(
        private readonly SyntaxHighlighter $highlighter,
        private readonly LineDiffer $lineDiffer,
        private readonly ?string $editorUrl = null,
    )
    {
        $cwd = getcwd();
        if ($cwd === false) {
            throw new LogicException('Failed to determine current working directory');
        }
        $this->basePath = $cwd;
    }

    /**
     * Generate a report of detected clone groups
     *
     * @param list<CloneGroup> $cloneGroups Array of detected clone groups
     * @param float $elapsedTime Elapsed time in seconds
     * @return string Formatted report text
     */
    public function report(
        array $cloneGroups,
        float $elapsedTime,
    ): string
    {
        $timeStr = sprintf('%.2fs', $elapsedTime);

        if (count($cloneGroups) === 0) {
            return sprintf("\n<fg=black;bg=green> No code clones detected, took %s </>\n", $timeStr);
        }

        $output = [];

        $groupNumber = 1;
        foreach ($cloneGroups as $group) {
            $output[] = '';
            $output[] = '  ' . str_repeat("\u{2500}", 37);
            $output[] = $this->formatCloneGroup($group, $groupNumber);
            $groupNumber++;
        }

        $output[] = '';
        $output[] = sprintf("  \u{2716} %d clone groups found (%s)\n", count($cloneGroups), $timeStr);

        return implode("\n", $output);
    }

    /**
     * Make a path relative to the base path (current working directory)
     */
    private function relativizePath(string $path): string
    {
        // Normalize paths
        $basePath = rtrim($this->basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $path = str_replace('\\', '/', $path);
        $basePath = str_replace('\\', '/', $basePath);

        // Make path relative if it starts with base path
        if (str_starts_with($path, $basePath)) {
            return substr($path, strlen($basePath));
        }

        return $path;
    }

    /**
     * Format and highlight a file path
     */
    private function formatPath(string $path): string
    {
        $relativePath = $this->relativizePath($path);
        return $this->highlighter->formatPath($relativePath);
    }

    /**
     * Wrap text in an OSC 8 hyperlink if editor URL is configured and colors are enabled
     */
    private function makeClickable(
        string $text,
        string $filePath,
        int $line,
    ): string
    {
        if ($this->editorUrl === null) {
            return $text;
        }

        if (!$this->highlighter->isEnabled()) {
            return $text;
        }

        $absolutePath = realpath($filePath);

        if ($absolutePath === false) {
            throw new LogicException("Failed to resolve real path for '{$filePath}'");
        }

        $url = str_replace(
            ['{relFile}', '{file}', '{line}'],
            [$this->relativizePath($filePath), $absolutePath, (string) $line],
            $this->editorUrl,
        );

        return "\033]8;;{$url}\033\\{$text}\033]8;;\033\\";
    }

    /**
     * Format a single clone group for display
     */
    private function formatCloneGroup(
        CloneGroup $group,
        int $index,
    ): string
    {
        $subtrees = $group->getSubtrees();
        $instanceCount = $group->getInstanceCount();
        $nodeCount = $group->getNodeCount();

        $allInstanceLines = $this->collectInstanceLines($subtrees);
        $diffRangesPerInstance = $this->lineDiffer->computeDiffRanges($allInstanceLines);
        $isExactMatch = $this->isExactMatch($allInstanceLines);

        $lineNumberWidth = $this->computeLineNumberWidth($subtrees);

        $output = [];
        $statusLine = sprintf(
            '  Clone #%d  %d nodes · %d instances',
            $index,
            $nodeCount,
            $instanceCount,
        );

        if ($isExactMatch) {
            $statusLine .= ' · exact match';
        }

        $output[] = $statusLine;
        $output[] = '';

        foreach ($subtrees as $subtree) {
            $location = sprintf(
                '%s:%d-%d',
                $this->formatPath($subtree->getFilePath()),
                $subtree->getStartLine(),
                $subtree->getEndLine(),
            );
            $output[] = '  ' . $this->makeClickable($location, $subtree->getFilePath(), $subtree->getStartLine());
        }

        $output[] = '';
        $output[] = $this->renderUnifiedCode($subtrees, $allInstanceLines, $diffRangesPerInstance, $lineNumberWidth);

        return implode("\n", $output);
    }

    /**
     * Determine the column width needed to print line numbers across all instances.
     *
     * @param list<Subtree> $subtrees
     */
    private function computeLineNumberWidth(array $subtrees): int
    {
        $maxLine = 0;
        foreach ($subtrees as $subtree) {
            if ($subtree->getEndLine() > $maxLine) {
                $maxLine = $subtree->getEndLine();
            }
        }
        $width = strlen((string) $maxLine);
        return $width < 3 ? 3 : $width;
    }

    /**
     * Check if all instances have identical source code
     *
     * @param list<list<string>> $allInstanceLines
     */
    private function isExactMatch(array $allInstanceLines): bool
    {
        if (count($allInstanceLines) < 2) {
            return true;
        }

        $first = $allInstanceLines[0];

        for ($i = 1; $i < count($allInstanceLines); $i++) {
            if ($allInstanceLines[$i] !== $first) {
                return false;
            }
        }

        return true;
    }

    /**
     * Collect code lines from all subtrees
     *
     * @param list<Subtree> $subtrees
     * @return list<list<string>>
     */
    private function collectInstanceLines(array $subtrees): array
    {
        $allLines = [];
        foreach ($subtrees as $subtree) {
            $code = $this->readOriginalSource(
                $subtree->getFilePath(),
                $subtree->getStartLine(),
                $subtree->getEndLine(),
            );
            $allLines[] = explode("\n", $code);
        }
        return $allLines;
    }

    /**
     * Render the unified code block for a clone group.
     *
     * Common lines (whitespace-equal across all instances) are shown once with the
     * first instance's source line number. Lines that differ are shown once per
     * instance, each prefixed with that instance's source line number. Falls back
     * to per-instance rendering when blank-line layouts make content-line counts
     * diverge.
     *
     * @param list<Subtree> $subtrees
     * @param list<list<string>> $allInstanceLines
     * @param list<list<list<array{int, int}>>> $diffRangesPerInstance
     */
    private function renderUnifiedCode(
        array $subtrees,
        array $allInstanceLines,
        array $diffRangesPerInstance,
        int $lineNumberWidth,
    ): string
    {
        if ($allInstanceLines === []) {
            return '';
        }

        // contentLineIdxsPerInstance[i] = list of original line indices that hold non-blank content
        $contentLineIdxsPerInstance = [];
        foreach ($allInstanceLines as $instanceLines) {
            $idxs = [];
            foreach ($instanceLines as $lineIdx => $line) {
                if (trim($line) !== '') {
                    $idxs[] = $lineIdx;
                }
            }
            $contentLineIdxsPerInstance[] = $idxs;
        }

        $contentCount = count($contentLineIdxsPerInstance[0]);
        foreach ($contentLineIdxsPerInstance as $idxs) {
            if (count($idxs) !== $contentCount) {
                return $this->renderPerInstanceFallback($subtrees, $allInstanceLines, $diffRangesPerInstance, $lineNumberWidth);
            }
        }

        $separator = $this->highlighter->formatDim("\u{2502}");
        $blankColumn = str_repeat(' ', $lineNumberWidth);
        $firstSubtree = $subtrees[0] ?? null;
        if ($firstSubtree === null) {
            throw new LogicException('Unified rendering: clone group with no instances');
        }

        $output = [];
        $contentIdx = 0;
        foreach ($allInstanceLines[0] as $lineIdx => $line) {
            if (trim($line) === '') {
                $output[] = sprintf('  %s %s ', $blankColumn, $separator);
                continue;
            }

            $variants = $this->collectVariantsAtPosition(
                $contentIdx,
                $allInstanceLines,
                $contentLineIdxsPerInstance,
                $diffRangesPerInstance,
            );
            $contentIdx++;

            $firstVariant = $variants[0];
            $firstTrimmed = trim($firstVariant[1]);
            $allSame = true;
            foreach ($variants as $variant) {
                if (trim($variant[1]) !== $firstTrimmed) {
                    $allSame = false;
                    break;
                }
            }

            if ($allSame) {
                $sourceLine = $firstSubtree->getStartLine() + $lineIdx;
                $output[] = sprintf(
                    '  %s %s %s',
                    $this->formatSourceLineNumber($firstSubtree, $sourceLine, $lineNumberWidth),
                    $separator,
                    $this->highlighter->highlight($firstVariant[1]),
                );
                continue;
            }

            foreach ($variants as [$instIdx, $instLine, $ranges, $origLineIdx]) {
                $subtree = $subtrees[$instIdx] ?? null;
                if ($subtree === null) {
                    throw new LogicException('Unified rendering: variant points to missing subtree');
                }
                $sourceLine = $subtree->getStartLine() + $origLineIdx;
                if ($ranges !== []) {
                    $highlighted = $this->highlighter->highlightWithDiffs($instLine, $ranges);
                } else {
                    $highlighted = $this->highlighter->highlight($instLine);
                }
                $output[] = sprintf(
                    '  %s %s %s',
                    $this->formatSourceLineNumber($subtree, $sourceLine, $lineNumberWidth),
                    $separator,
                    $highlighted,
                );
            }
        }

        return implode("\n", $output);
    }

    /**
     * Format a source line number, optionally wrapped in a clickable hyperlink.
     */
    private function formatSourceLineNumber(
        Subtree $subtree,
        int $sourceLine,
        int $width,
    ): string
    {
        $formatted = $this->highlighter->formatLineNumber($sourceLine, $width);
        return $this->makeClickable($formatted, $subtree->getFilePath(), $sourceLine);
    }

    /**
     * Collect each instance's line at a given content position along with its diff ranges.
     *
     * @param list<list<string>> $allInstanceLines
     * @param non-empty-list<list<int>> $contentLineIdxsPerInstance
     * @param list<list<list<array{int, int}>>> $diffRangesPerInstance
     * @return non-empty-list<array{int, string, list<array{int, int}>, int}>
     */
    private function collectVariantsAtPosition(
        int $contentIdx,
        array $allInstanceLines,
        array $contentLineIdxsPerInstance,
        array $diffRangesPerInstance,
    ): array
    {
        $variants = [];
        foreach ($contentLineIdxsPerInstance as $instIdx => $idxs) {
            if (!array_key_exists($contentIdx, $idxs) || !array_key_exists($instIdx, $allInstanceLines)) {
                throw new LogicException('Unified rendering: content alignment broke unexpectedly');
            }
            $origLineIdx = $idxs[$contentIdx];
            $instanceLines = $allInstanceLines[$instIdx];
            if (!array_key_exists($origLineIdx, $instanceLines)) {
                throw new LogicException('Unified rendering: original line index missing');
            }
            $ranges = $diffRangesPerInstance[$instIdx][$origLineIdx] ?? [];
            $variants[] = [$instIdx, $instanceLines[$origLineIdx], $ranges, $origLineIdx];
        }

        return $variants;
    }

    /**
     * Render each instance's code as a separate block, used when content-line
     * counts across instances do not align for a unified view.
     *
     * @param list<Subtree> $subtrees
     * @param list<list<string>> $allInstanceLines
     * @param list<list<list<array{int, int}>>> $diffRangesPerInstance
     */
    private function renderPerInstanceFallback(
        array $subtrees,
        array $allInstanceLines,
        array $diffRangesPerInstance,
        int $lineNumberWidth,
    ): string
    {
        $separator = $this->highlighter->formatDim("\u{2502}");
        $output = [];
        foreach ($allInstanceLines as $i => $instanceLines) {
            $subtree = $subtrees[$i] ?? null;
            if ($subtree === null) {
                throw new LogicException('Fallback rendering: instance index has no matching subtree');
            }
            foreach ($instanceLines as $lineIdx => $line) {
                $ranges = $diffRangesPerInstance[$i][$lineIdx] ?? [];
                if ($ranges !== []) {
                    $highlighted = $this->highlighter->highlightWithDiffs($line, $ranges);
                } else {
                    $highlighted = $this->highlighter->highlight($line);
                }
                $sourceLine = $subtree->getStartLine() + $lineIdx;
                $output[] = sprintf(
                    '  %s %s %s',
                    $this->formatSourceLineNumber($subtree, $sourceLine, $lineNumberWidth),
                    $separator,
                    $highlighted,
                );
            }
        }
        return implode("\n", $output);
    }

    /**
     * Read original source code from file preserving newlines
     */
    private function readOriginalSource(
        string $filePath,
        int $startLine,
        int $endLine,
    ): string
    {
        $lines = file($filePath, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new LogicException("Failed to read source file '{$filePath}'");
        }

        // Extract the relevant lines (file() is 0-indexed, but line numbers are 1-indexed)
        $relevantLines = array_slice($lines, $startLine - 1, $endLine - $startLine + 1);

        return implode("\n", $relevantLines);
    }

}
