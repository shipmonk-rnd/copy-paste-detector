<?php declare(strict_types = 1);

namespace ShipMonk\CopyPasteDetector\Hashing;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/**
 * Visitor cloning all nodes and linking to the original nodes using an attribute.
 *
 * This visitor is required to perform format-preserving operations and
 * is more efficient than serialize/unserialize for deep cloning AST nodes.
 */
final class CloningVisitor extends NodeVisitorAbstract
{

    public function enterNode(Node $node): Node
    {
        $clonedNode = clone $node;
        $clonedNode->setAttribute('origNode', $node);
        return $clonedNode;
    }

}
