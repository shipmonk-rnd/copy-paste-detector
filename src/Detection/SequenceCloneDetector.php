<?php declare(strict_types = 1);

namespace ShipMonk\CopyPasteDetector\Detection;

use LogicException;
use ShipMonk\CopyPasteDetector\AST\SiblingList;
use ShipMonk\CopyPasteDetector\AST\Subtree;
use function array_slice;
use function array_values;
use function count;
use function implode;
use function ksort;
use function md5;
use function sort;

/**
 * Finds Type-2 clones that span a contiguous *sequence of sibling statements*
 * but are not bounded by a single AST node — i.e. copy-pasted statement blocks
 * embedded in otherwise-different surroundings, which whole-subtree hashing
 * cannot detect.
 *
 * Algorithm (rolling-hash sliding window + greedy extension):
 *  1. For each sibling-stmt list and each window of K consecutive statements,
 *     compute a window hash from the per-stmt subtree hashes; bucket positions
 *     by window hash.
 *  2. For each bucket with ≥ 2 entries, greedily extend the whole group right
 *     and left as long as every entry still agrees on the next statement's hash.
 *  3. Deduplicate (the same maximal match is reached from every K-window inside
 *     it) and emit one CloneGroup per maximal sequence clone.
 *
 * The "extend whole group in lockstep" strategy intentionally trades some
 * n-way grouping fidelity for simplicity: a longer match between a subset of
 * the bucket's entries is still discovered from a different starting window
 * (whose content differs only for the diverging entries).
 */
final class SequenceCloneDetector
{

    public function __construct(
        private readonly int $minWindowStmts,
    )
    {
    }

    /**
     * @param list<SiblingList> $siblingLists
     * @return list<CloneGroup>
     */
    public function detect(
        array $siblingLists,
        int $minNodeCount,
    ): array
    {
        if ($this->minWindowStmts < 2 || $siblingLists === []) {
            return [];
        }

        $buckets = $this->buildWindowBuckets($siblingLists);

        $seen = [];
        $cloneGroups = [];

        foreach ($buckets as $entries) {
            if (count($entries) < 2) {
                continue;
            }

            $extended = $this->extendBucket($entries);
            $first = $extended[0];

            if ($this->sumNodeCount($first) < $minNodeCount) {
                continue;
            }

            $canonical = $this->canonicalKey($extended);
            if (isset($seen[$canonical])) {
                continue;
            }
            $seen[$canonical] = true;

            $cloneGroups[] = $this->buildCloneGroup($extended);
        }

        return $cloneGroups;
    }

    /**
     * @param list<SiblingList> $siblingLists
     * @return array<string, list<array{list: SiblingList, startPos: int, endPos: int}>>
     */
    private function buildWindowBuckets(array $siblingLists): array
    {
        $buckets = [];
        $k = $this->minWindowStmts;

        foreach ($siblingLists as $list) {
            $stmts = $list->getStmts();
            $n = count($stmts);
            if ($n < $k) {
                continue;
            }

            $lastStart = $n - $k;
            for ($i = 0; $i <= $lastStart; $i++) {
                $hashes = [];
                $window = array_slice($stmts, $i, $k);
                foreach ($window as $stmt) {
                    $hashes[] = $stmt->getHash();
                }
                $key = md5(implode('|', $hashes));
                $buckets[$key][] = ['list' => $list, 'startPos' => $i, 'endPos' => $i + $k - 1];
            }
        }

        return $buckets;
    }

    /**
     * Extend a bucket's entries left and right in lockstep, while every entry
     * still agrees on the neighbouring statement's hash.
     *
     * @param non-empty-list<array{list: SiblingList, startPos: int, endPos: int}> $entries
     * @return non-empty-list<array{list: SiblingList, startPos: int, endPos: int}>
     */
    private function extendBucket(array $entries): array
    {
        while (true) {
            $expectedHash = null;
            $canExtend = true;

            foreach ($entries as $entry) {
                $stmts = $entry['list']->getStmts();
                $nextPos = $entry['endPos'] + 1;
                if (!isset($stmts[$nextPos])) {
                    $canExtend = false;
                    break;
                }
                $hash = $stmts[$nextPos]->getHash();
                if ($expectedHash === null) {
                    $expectedHash = $hash;
                } elseif ($expectedHash !== $hash) {
                    $canExtend = false;
                    break;
                }
            }

            if (!$canExtend) {
                break;
            }

            foreach ($entries as $idx => $entry) {
                $entries[$idx]['endPos'] = $entry['endPos'] + 1;
            }
        }

        while (true) {
            $expectedHash = null;
            $canExtend = true;

            foreach ($entries as $entry) {
                $prevPos = $entry['startPos'] - 1;
                $stmts = $entry['list']->getStmts();
                if (!isset($stmts[$prevPos])) {
                    $canExtend = false;
                    break;
                }
                $hash = $stmts[$prevPos]->getHash();
                if ($expectedHash === null) {
                    $expectedHash = $hash;
                } elseif ($expectedHash !== $hash) {
                    $canExtend = false;
                    break;
                }
            }

            if (!$canExtend) {
                break;
            }

            foreach ($entries as $idx => $entry) {
                $entries[$idx]['startPos'] = $entry['startPos'] - 1;
            }
        }

        return $entries;
    }

    /**
     * @param array{list: SiblingList, startPos: int, endPos: int} $entry
     */
    private function sumNodeCount(array $entry): int
    {
        $sum = 0;
        foreach ($this->sliceStmts($entry) as $stmt) {
            $sum += $stmt->getNodeCount();
        }
        return $sum;
    }

    /**
     * Stable, instance-set-based key used to dedupe the same maximal match
     * discovered from multiple starting windows.
     *
     * @param non-empty-list<array{list: SiblingList, startPos: int, endPos: int}> $entries
     */
    private function canonicalKey(array $entries): string
    {
        $parts = [];
        foreach ($entries as $entry) {
            $parts[] = $this->rangeKey($entry);
        }
        sort($parts);
        return implode('|', $parts);
    }

    /**
     * @param non-empty-list<array{list: SiblingList, startPos: int, endPos: int}> $entries
     */
    private function buildCloneGroup(array $entries): CloneGroup
    {
        $first = $entries[0];

        $contentHashes = [];
        foreach ($this->sliceStmts($first) as $stmt) {
            $contentHashes[] = $stmt->getHash();
        }
        $sequenceHash = md5(implode('|', $contentHashes));
        $sharedNodeCount = $this->sumNodeCount($first);

        $orderedByFileLine = [];
        foreach ($entries as $entry) {
            [$start, $end] = $this->boundaryStmts($entry);
            $sortKey = $entry['list']->getFilePath() . ':' . $start->getStartLine();
            $orderedByFileLine[$sortKey] = new Subtree(
                $entry['list']->getFilePath(),
                $start->getStartLine(),
                $end->getEndLine(),
                $sharedNodeCount,
                $sequenceHash,
            );
        }

        ksort($orderedByFileLine);

        return new CloneGroup(array_values($orderedByFileLine));
    }

    /**
     * @param array{list: SiblingList, startPos: int, endPos: int} $entry
     * @return list<Subtree>
     */
    private function sliceStmts(array $entry): array
    {
        return array_slice(
            $entry['list']->getStmts(),
            $entry['startPos'],
            $entry['endPos'] - $entry['startPos'] + 1,
        );
    }

    /**
     * @param array{list: SiblingList, startPos: int, endPos: int} $entry
     * @return array{Subtree, Subtree}
     */
    private function boundaryStmts(array $entry): array
    {
        $stmts = $entry['list']->getStmts();
        $start = $stmts[$entry['startPos']] ?? throw new LogicException('startPos out of range');
        $end = $stmts[$entry['endPos']] ?? throw new LogicException('endPos out of range');
        return [$start, $end];
    }

    /**
     * @param array{list: SiblingList, startPos: int, endPos: int} $entry
     */
    private function rangeKey(array $entry): string
    {
        [$start, $end] = $this->boundaryStmts($entry);
        return $entry['list']->getFilePath()
            . ':' . $start->getStartLine()
            . '-' . $end->getEndLine();
    }

}
