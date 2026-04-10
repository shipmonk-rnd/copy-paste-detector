<?php declare(strict_types = 1);

namespace ShipMonk\CopyPasteDetector\Reporting;

use LogicException;
use ShipMonk\CopyPasteDetector\AST\Subtree;
use ShipMonk\CopyPasteDetector\Detection\CloneGroup;
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

        // Collect all code lines from all instances for diff comparison
        $allInstanceLines = $this->collectInstanceLines($subtrees);
        $diffRangesPerInstance = $this->lineDiffer->computeDiffRanges($allInstanceLines);

        $isExactMatch = $this->isExactMatch($allInstanceLines);

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

        $instanceIndex = 0;
        foreach ($subtrees as $subtree) {
            $filePath = $subtree->getFilePath();
            $startLine = $subtree->getStartLine();
            $endLine = $subtree->getEndLine();

            $formattedLocation = sprintf(
                '%s:%d-%d',
                $this->formatPath($filePath),
                $startLine,
                $endLine,
            );

            $output[] = '';
            $output[] = '  ' . $this->makeClickable($formattedLocation, $filePath, $startLine);
            $diffRanges = $diffRangesPerInstance[$instanceIndex] ?? [];
            $output[] = $this->formatCodeWithDiffs(
                $filePath,
                $startLine,
                $endLine,
                $diffRanges,
            );
            $instanceIndex++;
        }

        return implode("\n", $output);
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
     * Format source code with syntax highlighting and diff highlighting
     *
     * @param list<list<array{int, int}>> $diffRangesPerLine Diff ranges for each line
     */
    private function formatCodeWithDiffs(
        string $filePath,
        int $startLine,
        int $endLine,
        array $diffRangesPerLine,
    ): string
    {
        $code = $this->readOriginalSource($filePath, $startLine, $endLine);

        $lines = explode("\n", $code);
        $formatted = [];
        $maxLineNumber = $startLine + count($lines) - 1;
        $width = strlen((string) $maxLineNumber);
        if ($width < 3) {
            $width = 3;
        }

        foreach ($lines as $i => $line) {
            $diffRanges = $diffRangesPerLine[$i] ?? [];
            if ($diffRanges !== []) {
                $highlightedLine = $this->highlighter->highlightWithDiffs($line, $diffRanges);
            } else {
                $highlightedLine = $this->highlighter->highlight($line);
            }
            $lineNum = $this->highlighter->formatLineNumber($startLine + $i, $width);
            $separator = $this->highlighter->formatDim("\u{2502}");
            $formatted[] = sprintf('  %s %s %s', $lineNum, $separator, $highlightedLine);
        }

        return implode("\n", $formatted);
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
