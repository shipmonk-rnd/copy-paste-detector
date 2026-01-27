<?php declare(strict_types = 1);

namespace ShipMonk\CopyPasteDetector\Detection;

use LogicException;
use ShipMonk\CopyPasteDetector\AST\Subtree;
use function array_key_exists;
use function count;

/**
 * Represents a group of identical code clones
 */
final class CloneGroup
{

    /**
     * @var list<Subtree>
     */
    private array $subtrees;

    /**
     * @param list<Subtree> $subtrees
     */
    public function __construct(array $subtrees)
    {
        if (count($subtrees) < 2) {
            throw new LogicException('A clone group must contain at least 2 subtrees');
        }

        $this->subtrees = $subtrees;
    }

    /**
     * @return list<Subtree>
     */
    public function getSubtrees(): array
    {
        return $this->subtrees;
    }

    /**
     * Get the number of clone instances in this group
     */
    public function getInstanceCount(): int
    {
        return count($this->subtrees);
    }

    /**
     * Get the node count (all subtrees have the same count)
     */
    public function getNodeCount(): int
    {
        if (!array_key_exists(0, $this->subtrees)) {
            throw new LogicException('Clone group must contain at least one subtree');
        }

        return $this->subtrees[0]->getNodeCount();
    }

}
