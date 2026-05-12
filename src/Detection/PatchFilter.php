<?php declare(strict_types = 1);

namespace ShipMonk\CopyPasteDetector\Detection;

/**
 * Keeps only clone groups that are relevant to a given patch:
 * at least one instance must lie fully inside the patch's added lines.
 *
 * Groups whose instances are all unchanged are dropped (the clone already existed
 * before the patch and isn't introduced by it). Groups whose instances are all
 * inside the patch are retained as intra-MR duplication.
 */
final class PatchFilter
{

    public function __construct(
        private readonly ChangedLines $changedLines,
    )
    {
    }

    /**
     * @param list<CloneGroup> $cloneGroups
     * @return list<CloneGroup>
     */
    public function filter(array $cloneGroups): array
    {
        $survivors = [];

        foreach ($cloneGroups as $group) {
            foreach ($group->getSubtrees() as $subtree) {
                if ($this->changedLines->containsRange($subtree->getFilePath(), $subtree->getStartLine(), $subtree->getEndLine())) {
                    $survivors[] = $group;
                    break;
                }
            }
        }

        return $survivors;
    }

}
