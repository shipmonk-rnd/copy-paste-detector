<?php declare(strict_types = 1);

namespace ShipMonk\CopyPasteDetector\AST;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

/**
 * Counts the number of nodes in an AST subtree
 */
final class NodeCounter
{

    /**
     * Count the total number of nodes in a subtree
     *
     * @param Node $root Root node of the subtree
     * @return int Total node count
     */
    public function count(Node $root): int
    {
        $visitor = new class extends NodeVisitorAbstract {

            private int $count = 0;

            public function enterNode(Node $node): int|Node|null
            {
                $this->count++;
                return null;
            }

            public function getCount(): int
            {
                return $this->count;
            }

        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse([$root]);

        return $visitor->getCount();
    }

}
