<?php declare(strict_types = 1);

namespace ShipMonk\CopyPasteDetector\AST;

use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use ShipMonk\CopyPasteDetector\Hashing\SubtreeHasher;

/**
 * Extracts all subtrees from an AST that meet the minimum node count threshold,
 * plus every sibling-stmt list (any array<Node> AST member with ≥ 2 Node entries)
 * for downstream sequence-clone detection.
 */
final class SubtreeExtractor
{

    public function __construct(
        private readonly SubtreeHasher $hasher,
    )
    {
    }

    /**
     * @param list<Stmt> $ast Array of AST nodes (statements)
     * @param string $filePath Source file path for metadata
     * @param int $minNodeCount Minimum number of nodes required for a subtree
     */
    public function extract(
        array $ast,
        string $filePath,
        int $minNodeCount,
    ): ExtractionResult
    {
        $visitor = new SubtreeVisitor($minNodeCount, $filePath, $this->hasher);

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        return new ExtractionResult(
            $visitor->getSubtrees(),
            $visitor->getSiblingLists(),
        );
    }

}
