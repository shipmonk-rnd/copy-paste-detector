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
use function max;
use function min;
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
        $allInstanceLines = $this->dedentInstanceLines($allInstanceLines);
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
     * Strip the longest leading-whitespace prefix common to each instance's
     * non-blank lines. Snippets that live at different indentation depths in
     * their source files end up aligned at column 0 in the unified view while
     * relative indentation within a snippet is preserved.
     *
     * @param list<list<string>> $allInstanceLines
     * @return list<list<string>>
     */
    private function dedentInstanceLines(array $allInstanceLines): array
    {
        $result = [];
        foreach ($allInstanceLines as $instanceLines) {
            $result[] = $this->dedentLines($instanceLines);
        }
        return $result;
    }

    /**
     * @param list<string> $lines
     * @return list<string>
     */
    private function dedentLines(array $lines): array
    {
        $prefix = null;
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $leading = $this->extractLeadingWhitespace($line);
            if ($prefix === null) {
                $prefix = $leading;
                continue;
            }
            $prefix = $this->commonStringPrefix($prefix, $leading);
            if ($prefix === '') {
                return $lines;
            }
        }

        if ($prefix === null || $prefix === '') {
            return $lines;
        }

        $prefixLen = strlen($prefix);
        $dedented = [];
        foreach ($lines as $line) {
            if (str_starts_with($line, $prefix)) {
                $dedented[] = substr($line, $prefixLen);
                continue;
            }
            // Pure-whitespace line shorter than the prefix; render as empty.
            $dedented[] = '';
        }
        return $dedented;
    }

    private function extractLeadingWhitespace(string $line): string
    {
        $len = strlen($line);
        for ($i = 0; $i < $len; $i++) {
            $char = $line[$i];
            if ($char !== ' ' && $char !== "\t") {
                return substr($line, 0, $i);
            }
        }
        return $line;
    }

    private function commonStringPrefix(
        string $a,
        string $b,
    ): string
    {
        $minLen = min(strlen($a), strlen($b));
        for ($i = 0; $i < $minLen; $i++) {
            if ($a[$i] !== $b[$i]) {
                return substr($a, 0, $i);
            }
        }
        return substr($a, 0, $minLen);
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

        $firstSubtree = $subtrees[0] ?? null;
        if ($firstSubtree === null) {
            throw new LogicException('Unified rendering: clone group with no instances');
        }

        $rows = $this->buildUnifiedRows(
            $subtrees,
            $allInstanceLines,
            $diffRangesPerInstance,
            $contentLineIdxsPerInstance,
            $firstSubtree,
        );

        $labelWidth = $lineNumberWidth;
        foreach ($rows as $row) {
            if ($row['kind'] !== 'code') {
                continue;
            }
            $width = $this->lineNumbersVisualWidth($row['lineNums']);
            if ($width > $labelWidth) {
                $labelWidth = $width;
            }
        }

        $separator = $this->highlighter->formatDim("\u{2502}");
        $blankColumn = str_repeat(' ', $labelWidth);

        $output = [];
        foreach ($rows as $row) {
            if ($row['kind'] === 'blank') {
                $output[] = sprintf('  %s %s ', $blankColumn, $separator);
                continue;
            }
            $highlighted = $row['ranges'] !== []
                ? $this->highlighter->highlightWithDiffs($row['text'], $row['ranges'])
                : $this->highlighter->highlight($row['text']);
            $output[] = sprintf(
                '  %s %s %s',
                $this->formatLineLabel($row['lineNums'], $labelWidth),
                $separator,
                $highlighted,
            );
        }

        return implode("\n", $output);
    }

    /**
     * Build the structured rows that make up the unified code block.
     *
     * Common lines collapse to a single row using the first instance's source line.
     * Divergent positions split into one row per unique (post-dedent) text; the row's
     * label lists every instance line that produced that text, in instance order.
     *
     * @param list<Subtree> $subtrees
     * @param non-empty-list<list<string>> $allInstanceLines
     * @param list<list<list<array{int, int}>>> $diffRangesPerInstance
     * @param non-empty-list<list<int>> $contentLineIdxsPerInstance
     * @return list<array{kind: 'blank'} | array{kind: 'code', lineNums: non-empty-list<array{Subtree, int}>, text: string, ranges: list<array{int, int}>}>
     */
    private function buildUnifiedRows(
        array $subtrees,
        array $allInstanceLines,
        array $diffRangesPerInstance,
        array $contentLineIdxsPerInstance,
        Subtree $firstSubtree,
    ): array
    {
        $rows = [];
        $contentIdx = 0;
        foreach ($allInstanceLines[0] as $lineIdx => $line) {
            if (trim($line) === '') {
                $rows[] = ['kind' => 'blank'];
                continue;
            }

            $variants = $this->collectVariantsAtPosition(
                $contentIdx,
                $allInstanceLines,
                $contentLineIdxsPerInstance,
                $diffRangesPerInstance,
            );
            $contentIdx++;

            $firstTrimmed = trim($variants[0][1]);
            $allSame = true;
            foreach ($variants as $variant) {
                if (trim($variant[1]) !== $firstTrimmed) {
                    $allSame = false;
                    break;
                }
            }

            if ($allSame) {
                $sourceLine = $firstSubtree->getStartLine() + $lineIdx;
                $rows[] = [
                    'kind' => 'code',
                    'lineNums' => [[$firstSubtree, $sourceLine]],
                    'text' => $variants[0][1],
                    'ranges' => [],
                ];
                continue;
            }

            /** @var list<array{lineNums: non-empty-list<array{Subtree, int}>, text: string, ranges: list<array{int, int}>}> $orderedGroups */
            $orderedGroups = [];
            /** @var array<string, int> $groupIndexByKey */
            $groupIndexByKey = [];
            foreach ($variants as [$instIdx, $instText, $ranges, $origLineIdx]) {
                $subtree = $subtrees[$instIdx] ?? null;
                if ($subtree === null) {
                    throw new LogicException('Unified rendering: variant points to missing subtree');
                }
                $sourceLine = $subtree->getStartLine() + $origLineIdx;
                $key = trim($instText);
                if (!array_key_exists($key, $groupIndexByKey)) {
                    $groupIndexByKey[$key] = count($orderedGroups);
                    $orderedGroups[] = [
                        'lineNums' => [[$subtree, $sourceLine]],
                        'text' => $instText,
                        'ranges' => $ranges,
                    ];
                    continue;
                }
                $idx = $groupIndexByKey[$key];
                if (!array_key_exists($idx, $orderedGroups)) {
                    throw new LogicException('Unified rendering: group index out of range');
                }
                $existing = $orderedGroups[$idx];
                $appendedLineNums = $existing['lineNums'];
                $appendedLineNums[] = [$subtree, $sourceLine];
                $orderedGroups[$idx] = [
                    'lineNums' => $appendedLineNums,
                    'text' => $existing['text'],
                    'ranges' => $existing['ranges'],
                ];
            }
            foreach ($orderedGroups as $group) {
                $rows[] = [
                    'kind' => 'code',
                    'lineNums' => $group['lineNums'],
                    'text' => $group['text'],
                    'ranges' => $group['ranges'],
                ];
            }
        }

        return $rows;
    }

    /**
     * Visual character width of a line-number label like "47, 59".
     *
     * @param non-empty-list<array{Subtree, int}> $lineNums
     */
    private function lineNumbersVisualWidth(array $lineNums): int
    {
        $width = 0;
        foreach ($lineNums as $i => $pair) {
            if ($i > 0) {
                $width += 2; // ", "
            }
            $width += strlen((string) $pair[1]);
        }
        return $width;
    }

    /**
     * Format a line-number label, right-aligned within the given total width. Multiple
     * source line numbers are joined with ", " and each is independently clickable.
     *
     * @param non-empty-list<array{Subtree, int}> $lineNums
     */
    private function formatLineLabel(
        array $lineNums,
        int $totalWidth,
    ): string
    {
        $parts = [];
        foreach ($lineNums as [$subtree, $sourceLine]) {
            $digits = strlen((string) $sourceLine);
            $formatted = $this->highlighter->formatLineNumber($sourceLine, $digits);
            $parts[] = $this->makeClickable($formatted, $subtree->getFilePath(), $sourceLine);
        }
        $joined = implode(', ', $parts);
        $padding = str_repeat(' ', max(0, $totalWidth - $this->lineNumbersVisualWidth($lineNums)));
        return $padding . $joined;
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
                    $this->formatLineLabel([[$subtree, $sourceLine]], $lineNumberWidth),
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
