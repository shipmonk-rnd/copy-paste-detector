<?php declare(strict_types = 1);

namespace ShipMonk\CopyPasteDetector\Hashing;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/**
 * Visitor that deep clones all AST nodes.
 *
 * More efficient than serialize/unserialize for deep cloning AST nodes.
 */
final class CloningVisitor extends NodeVisitorAbstract
{

    public function enterNode(Node $node): Node
    {
        return clone $node;
    }

}
