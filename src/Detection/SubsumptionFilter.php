<?php declare(strict_types = 1);

namespace ShipMonk\CopyPasteDetector\Detection;

use function usort;

/**
 * Drops clone groups whose every instance is fully contained inside an instance
 * of some strictly larger surviving group (so e.g. inner statements of a foreach
 * are not reported separately when the whole foreach is already reported).
 *
 * A group survives if at least one of its instances is NOT covered by any
 * surviving larger group — i.e. partially subsumed groups are retained.
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

        /** @var array<string, list<array{startLine: int, endLine: int, nodeCount: int}>> $survivingByFile */
        $survivingByFile = [];
        $survivors = [];

        foreach ($cloneGroups as $group) {
            $groupNodeCount = $group->getNodeCount();
            $allSubsumed = true;

            foreach ($group->getSubtrees() as $subtree) {
                $covered = false;

                foreach ($survivingByFile[$subtree->getFilePath()] ?? [] as $range) {
                    if (
                        $range['nodeCount'] > $groupNodeCount
                        && $range['startLine'] <= $subtree->getStartLine()
                        && $range['endLine'] >= $subtree->getEndLine()
                    ) {
                        $covered = true;
                        break;
                    }
                }

                if (!$covered) {
                    $allSubsumed = false;
                    break;
                }
            }

            if ($allSubsumed) {
                continue;
            }

            $survivors[] = $group;

            foreach ($group->getSubtrees() as $subtree) {
                $survivingByFile[$subtree->getFilePath()][] = [
                    'startLine' => $subtree->getStartLine(),
                    'endLine' => $subtree->getEndLine(),
                    'nodeCount' => $groupNodeCount,
                ];
            }
        }

        return $survivors;
    }

}
