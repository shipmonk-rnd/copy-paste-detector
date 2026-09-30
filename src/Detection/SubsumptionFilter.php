<?php declare(strict_types = 1);

namespace ShipMonk\CopyPasteDetector\Detection;

use function array_intersect_key;
use function count;
use function usort;

/**
 * Drops clone groups whose every instance is fully contained inside an instance
 * of one strictly larger surviving group (so e.g. inner statements of a foreach
 * are not reported separately when the whole foreach is already reported).
 *
 * All instances must be covered by the same larger group. When different larger
 * groups cover different instances, the smaller group is the only one that shows
 * the clone relation between those instances, so it survives. Partially subsumed
 * groups are retained too.
 */
final class SubsumptionFilter
{

    /**
     * @param list<CloneGroup> $cloneGroups
     * @return list<CloneGroup>
     */
    public function filter(array $cloneGroups): array
    {
        usort($cloneGroups, static fn (CloneGroup $a, CloneGroup $b): int => $b->getNodeCount() <=> $a->getNodeCount());

        /** @var array<string, list<array{startLine: int, endLine: int, nodeCount: int, survivorIndex: int}>> $survivingByFile */
        $survivingByFile = [];
        $survivors = [];

        foreach ($cloneGroups as $group) {
            $groupNodeCount = $group->getNodeCount();

            /** @var array<int, true>|null $commonCoveringSurvivors */
            $commonCoveringSurvivors = null;

            foreach ($group->getSubtrees() as $subtree) {
                $coveringSurvivors = [];

                foreach ($survivingByFile[$subtree->getFilePath()] ?? [] as $range) {
                    if (
                        $range['nodeCount'] > $groupNodeCount
                        && $range['startLine'] <= $subtree->getStartLine()
                        && $range['endLine'] >= $subtree->getEndLine()
                    ) {
                        $coveringSurvivors[$range['survivorIndex']] = true;
                    }
                }

                $commonCoveringSurvivors = $commonCoveringSurvivors === null
                    ? $coveringSurvivors
                    : array_intersect_key($commonCoveringSurvivors, $coveringSurvivors);

                if ($commonCoveringSurvivors === []) {
                    break;
                }
            }

            if ($commonCoveringSurvivors !== []) {
                continue;
            }

            $survivorIndex = count($survivors);
            $survivors[] = $group;

            foreach ($group->getSubtrees() as $subtree) {
                $survivingByFile[$subtree->getFilePath()][] = [
                    'startLine' => $subtree->getStartLine(),
                    'endLine' => $subtree->getEndLine(),
                    'nodeCount' => $groupNodeCount,
                    'survivorIndex' => $survivorIndex,
                ];
            }
        }

        return $survivors;
    }

}
