<?php declare(strict_types = 1);

namespace CopyPasteDetector\AST;

use CopyPasteDetector\Hashing\SubtreeHasher;
use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/**
 * Visitor that collects all subtrees meeting the minimum node count threshold
 */
final class SubtreeVisitor extends NodeVisitorAbstract
{

    /**
     * @var list<Subtree>
     */
    private array $subtrees = [];

    public function __construct(
        private readonly int $minNodeCount,
        private readonly string $filePath,
        private readonly NodeCounter $nodeCounter,
        private readonly SubtreeHasher $hasher,
    )
    {
    }

    public function enterNode(Node $node): int|Node|null
    {
        // Count nodes in this subtree
        $nodeCount = $this->nodeCounter->count($node);

        // Only include subtrees that meet the minimum node count threshold
        if ($nodeCount >= $this->minNodeCount) {
            $startLine = $node->getStartLine();
            $endLine = $node->getEndLine();

            // Skip if line information is missing
            if ($startLine === -1 || $endLine === -1) {
                return null;
            }

            // Calculate hash once when creating the subtree
            $hash = $this->hasher->hashNode($node);

            $this->subtrees[] = new Subtree(
                $node,
                $this->filePath,
                $startLine,
                $endLine,
                $nodeCount,
                $hash,
            );
        }

        return null;
    }

    /**
     * @return list<Subtree>
     */
    public function getSubtrees(): array
    {
        return $this->subtrees;
    }

}
