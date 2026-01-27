<?php declare(strict_types = 1);

namespace CopyPasteDetector\AST;

use CopyPasteDetector\Hashing\SubtreeHasher;
use LogicException;
use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;
use SplObjectStorage;
use function is_array;

/**
 * Visitor that collects all subtrees meeting the minimum node count threshold.
 * Uses bottom-up counting for O(n) complexity instead of O(n²).
 */
final class SubtreeVisitor extends NodeVisitorAbstract
{

    /**
     * @var list<Subtree>
     */
    private array $subtrees = [];

    /**
     * @var SplObjectStorage<Node, int>
     */
    private SplObjectStorage $nodeCounts;

    public function __construct(
        private readonly int $minNodeCount,
        private readonly string $filePath,
        private readonly SubtreeHasher $hasher,
    )
    {
        $this->nodeCounts = new SplObjectStorage();
    }

    /**
     * @param array<Node> $nodes
     * @return array<Node>|null
     */
    public function beforeTraverse(array $nodes): ?array
    {
        $this->nodeCounts = new SplObjectStorage();
        $this->subtrees = [];
        return null;
    }

    /**
     * @param array<Node> $nodes
     * @return array<Node>|null
     */
    public function afterTraverse(array $nodes): ?array
    {
        $this->nodeCounts = new SplObjectStorage();
        return null;
    }

    public function leaveNode(Node $node): int|Node|null
    {
        // Count this node + all children using bottom-up approach
        $count = 1;

        foreach ($node->getSubNodeNames() as $name) {
            $subNode = $node->{$name}; // @phpstan-ignore property.dynamicName

            if ($subNode instanceof Node) {
                $count += $this->nodeCounts[$subNode] ?? throw new LogicException('Node without count: ' . $subNode::class);
            } elseif (is_array($subNode)) {
                foreach ($subNode as $child) {
                    if ($child instanceof Node) {
                        $count += $this->nodeCounts[$child] ?? throw new LogicException('Node without count: ' . $child::class);
                    }
                }
            }
        }

        $this->nodeCounts[$node] = $count;

        // Only include subtrees that meet the minimum node count threshold
        if ($count >= $this->minNodeCount) {
            $startLine = $node->getStartLine();
            $endLine = $node->getEndLine();

            // Skip if line information is missing
            if ($startLine === -1 || $endLine === -1) {
                return null;
            }

            // Calculate hash once when creating the subtree
            $hash = $this->hasher->hashNode($node);

            $this->subtrees[] = new Subtree(
                $this->filePath,
                $startLine,
                $endLine,
                $count,
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
