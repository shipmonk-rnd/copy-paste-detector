<?php declare(strict_types = 1);

namespace ShipMonk\CopyPasteDetector\Reporting;

use function array_map;
use function assert;
use function count;
use function is_int;
use function min;
use function strlen;
use function strpos;
use function substr;
use function trim;

/**
 * Computes character-level diff ranges across multiple code instances
 */
final class LineDiffer
{

    private const MIN_COMMON_LENGTH = 3;

    /**
     * Compute diff ranges for each instance by comparing all instances
     *
     * @param list<list<string>> $allInstanceLines
     * @return list<list<list<array{int, int}>>> Diff ranges per instance, per line
     */
    public function computeDiffRanges(array $allInstanceLines): array
    {
        $instanceCount = count($allInstanceLines);
        if ($instanceCount < 2 || $allInstanceLines === []) {
            return [];
        }

        // Initialize empty diff ranges for each instance's original lines
        /** @var list<list<list<array{int, int}>>> $diffRangesPerInstance */
        $diffRangesPerInstance = [];

        foreach ($allInstanceLines as $instanceLines) {
            /** @var list<list<array{int, int}>> $instanceDiffs */
            $instanceDiffs = [];
            for ($lineIdx = 0; $lineIdx < count($instanceLines); $lineIdx++) {
                $instanceDiffs[] = [];
            }
            $diffRangesPerInstance[] = $instanceDiffs;
        }

        // Extract non-blank lines with mappings to original line indices
        /** @var list<list<string>> $contentLinesPerInstance */
        $contentLinesPerInstance = [];
        /** @var list<list<int>> $lineMapPerInstance */
        $lineMapPerInstance = [];

        foreach ($allInstanceLines as $instanceLines) {
            /** @var list<string> $contentLines */
            $contentLines = [];
            /** @var list<int> $lineMap */
            $lineMap = [];
            foreach ($instanceLines as $lineIdx => $line) {
                if (trim($line) !== '') {
                    $lineMap[] = $lineIdx;
                    $contentLines[] = $line;
                }
            }
            $contentLinesPerInstance[] = $contentLines;
            $lineMapPerInstance[] = $lineMap;
        }

        // Verify all instances have the same number of content lines
        $contentLineCount = count($contentLinesPerInstance[0]);
        foreach ($contentLinesPerInstance as $contentLines) {
            if (count($contentLines) !== $contentLineCount) {
                return $diffRangesPerInstance;
            }
        }

        // Compare each content line across all instances
        for ($contentIdx = 0; $contentIdx < $contentLineCount; $contentIdx++) {
            /** @var non-empty-list<string> $linesAtPosition */
            $linesAtPosition = [];
            foreach ($contentLinesPerInstance as $contentLines) {
                $linesAtPosition[] = $contentLines[$contentIdx]; // @phpstan-ignore offsetAccess.notFound
            }

            // Check if all lines are identical
            $firstLine = $linesAtPosition[0];
            $allSame = true;
            foreach ($linesAtPosition as $idx => $lineAtPos) {
                if ($idx === 0) {
                    continue;
                }
                if ($lineAtPos !== $firstLine) {
                    $allSame = false;
                    break;
                }
            }

            if ($allSame) {
                continue;
            }

            // Find diff ranges recursively to support multiple disjoint diff regions
            /** @var list<array{string, int}> $segments */
            $segments = [];
            foreach ($linesAtPosition as $line) {
                $segments[] = [$line, 0];
            }

            /** @var list<list<array{int, int}>> $lineRanges */
            $lineRanges = [];
            for ($i = 0; $i < count($linesAtPosition); $i++) {
                $lineRanges[] = [];
            }

            $this->findDiffRangesRecursive($segments, $lineRanges);

            // Map results back to original line indices
            foreach ($lineRanges as $inst => $ranges) {
                $originalLineIdx = $lineMapPerInstance[$inst][$contentIdx]; // @phpstan-ignore offsetAccess.notFound, offsetAccess.notFound
                $diffRangesPerInstance[$inst][$originalLineIdx] = $ranges;
            }
        }

        return $diffRangesPerInstance;
    }

    /**
     * Recursively find diff ranges by splitting on common substrings
     *
     * @param list<array{string, int}> $segments [substring, offset_in_original] per instance
     * @param list<list<array{int, int}>> $ranges Output ranges per instance (modified by reference)
     */
    private function findDiffRangesRecursive(
        array $segments,
        array &$ranges,
    ): void
    {
        if ($segments === []) {
            return;
        }

        /** @var non-empty-list<string> $strings */
        $strings = array_map(static fn (array $s): string => $s[0], $segments);

        // If all strings are identical, no diff
        $first = $strings[0];
        $allSame = true;
        foreach ($strings as $s) {
            if ($s !== $first) {
                $allSame = false;
                break;
            }
        }
        if ($allSame) {
            return;
        }

        // Find common prefix and suffix
        $prefixLen = $this->commonPrefixLength($strings);
        $suffixLen = $this->commonSuffixLength($strings, $prefixLen);

        // Extract the differing middles
        /** @var list<array{string, int}> $middles */
        $middles = [];
        /** @var list<string> $middleStrings */
        $middleStrings = [];
        foreach ($segments as [$str, $offset]) {
            $len = strlen($str);
            $middleStr = substr($str, $prefixLen, $len - $prefixLen - $suffixLen);
            $middles[] = [$middleStr, $offset + $prefixLen];
            $middleStrings[] = $middleStr;
        }

        // Try to find a common substring within the middles to split on
        $commonSub = $this->longestCommonSubstring($middleStrings);

        if ($commonSub === null) {
            // No common substring found — mark entire middle as diff for each instance
            foreach ($middles as $i => [$str, $offset]) {
                if ($str !== '') {
                    $ranges[$i][] = [$offset, $offset + strlen($str)];
                }
            }
            return;
        }

        // Split each middle at the common substring and recurse on left and right parts
        /** @var list<array{string, int}> $leftSegments */
        $leftSegments = [];
        /** @var list<array{string, int}> $rightSegments */
        $rightSegments = [];
        $commonLen = strlen($commonSub);

        foreach ($middles as [$str, $offset]) {
            $pos = strpos($str, $commonSub);
            assert(is_int($pos));
            $leftSegments[] = [substr($str, 0, $pos), $offset];
            $rightSegments[] = [substr($str, $pos + $commonLen), $offset + $pos + $commonLen];
        }

        $this->findDiffRangesRecursive($leftSegments, $ranges);
        $this->findDiffRangesRecursive($rightSegments, $ranges);
    }

    /**
     * Find the longest substring present in all strings
     *
     * @param list<string> $strings
     * @return string|null The longest common substring, or null if none >= MIN_COMMON_LENGTH
     */
    private function longestCommonSubstring(array $strings): ?string
    {
        if ($strings === []) {
            return null;
        }

        // Use the shortest string as the base for candidate substrings
        $shortest = $strings[0];
        $shortestIdx = 0;
        foreach ($strings as $i => $s) {
            if (strlen($s) < strlen($shortest)) {
                $shortest = $s;
                $shortestIdx = $i;
            }
        }

        $len = strlen($shortest);

        // Try substrings from longest to shortest
        for ($subLen = $len; $subLen >= self::MIN_COMMON_LENGTH; $subLen--) {
            for ($start = 0; $start <= $len - $subLen; $start++) {
                $candidate = substr($shortest, $start, $subLen);
                $foundInAll = true;
                foreach ($strings as $i => $s) {
                    if ($i === $shortestIdx) {
                        continue;
                    }
                    if (strpos($s, $candidate) === false) {
                        $foundInAll = false;
                        break;
                    }
                }
                if ($foundInAll) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /**
     * Find the length of the common prefix across all lines
     *
     * @param non-empty-list<string> $lines
     */
    private function commonPrefixLength(array $lines): int
    {
        $minLen = min(array_map(strlen(...), $lines));
        $prefixLen = 0;

        for ($i = 0; $i < $minLen; $i++) {
            $char = $lines[0][$i];
            foreach ($lines as $line) {
                if ($line[$i] !== $char) {
                    return $prefixLen;
                }
            }
            $prefixLen++;
        }

        return $prefixLen;
    }

    /**
     * Find the length of the common suffix across all lines, not overlapping with the prefix
     *
     * @param non-empty-list<string> $lines
     */
    private function commonSuffixLength(
        array $lines,
        int $prefixLen,
    ): int
    {
        $suffixLen = 0;

        while (true) {
            $nextSuffix = $suffixLen + 1;
            $char = null;
            $allMatch = true;

            foreach ($lines as $line) {
                $len = strlen($line);

                // Don't let suffix overlap with prefix
                if ($len - $nextSuffix < $prefixLen) {
                    return $suffixLen;
                }

                $c = $line[$len - $nextSuffix];
                if ($char === null) {
                    $char = $c;
                } elseif ($c !== $char) {
                    $allMatch = false;
                    break;
                }
            }

            if (!$allMatch) {
                return $suffixLen;
            }

            $suffixLen = $nextSuffix;
        }
    }

}
