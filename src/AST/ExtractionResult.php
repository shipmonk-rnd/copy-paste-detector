<?php declare(strict_types = 1);

namespace ShipMonk\CopyPasteDetector\AST;

/**
 * Output of {@see SubtreeExtractor}: whole-subtree candidates plus sibling-statement
 * lists used by the sequence-based clone detector.
 */
final class ExtractionResult
{

    /**
     * @param list<Subtree> $subtrees Subtrees that meet the minimum node count threshold.
     * @param list<SiblingList> $siblingLists All sibling-stmt lists of length ≥ 2 in the AST.
     */
    public function __construct(
        private readonly array $subtrees,
        private readonly array $siblingLists,
    )
    {
    }

    /**
     * @return list<Subtree>
     */
    public function getSubtrees(): array
    {
        return $this->subtrees;
    }

    /**
     * @return list<SiblingList>
     */
    public function getSiblingLists(): array
    {
        return $this->siblingLists;
    }

}
