<?php declare(strict_types = 1);

namespace ShipMonk\CopyPasteDetector\AST;

use LogicException;
use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;
use ShipMonk\CopyPasteDetector\Hashing\SubtreeHasher;
use SplObjectStorage;
use function count;
use function is_array;

/**
 * Visitor that collects all subtrees meeting the minimum node count threshold
 * and, additionally, every sibling-statement list (array<Node> child) of length ≥ 2.
 *
 * Uses bottom-up counting for O(n) complexity instead of O(n²).
 */
final class SubtreeVisitor extends NodeVisitorAbstract
{

    /**
     * @var list<Subtree>
     */
    private array $subtrees = [];

    /**
     * @var list<SiblingList>
     */
    private array $siblingLists = [];

    /**
     * @var SplObjectStorage<Node, int>
     */
    private SplObjectStorage $nodeCounts;

    /**
     * @var SplObjectStorage<Node, string>
     */
    private SplObjectStorage $nodeHashes;

    public function __construct(
        private readonly int $minNodeCount,
        private readonly string $filePath,
        private readonly SubtreeHasher $hasher,
    )
    {
        $this->nodeCounts = new SplObjectStorage();
        $this->nodeHashes = new SplObjectStorage();
    }

    /**
     * @param array<Node> $nodes
     * @return array<Node>|null
     */
    public function beforeTraverse(array $nodes): ?array
    {
        $this->nodeCounts = new SplObjectStorage();
        $this->nodeHashes = new SplObjectStorage();
        $this->subtrees = [];
        $this->siblingLists = [];
        return null;
    }

    /**
     * @param array<Node> $nodes
     * @return array<Node>|null
     */
    public function afterTraverse(array $nodes): ?array
    {
        // Synthetic sibling list for the file's top-level statements
        // (they have no enclosing AST node, so leaveNode won't emit them).
        $this->emitSiblingListFromArray($nodes);

        $this->nodeCounts = new SplObjectStorage();
        $this->nodeHashes = new SplObjectStorage();
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

                $this->emitSiblingListFromArray($subNode);
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

            $this->subtrees[] = new Subtree(
                $this->filePath,
                $startLine,
                $endLine,
                $count,
                $this->getNodeHash($node),
            );
        }

        return null;
    }

    /**
     * Build and store a SiblingList covering the Node elements of $arr.
     *
     * Non-Node entries (e.g. nullable list slots) split the sequence so that
     * we never emit a list with "holes" — only contiguous runs of Nodes.
     *
     * @param array<mixed> $arr
     */
    private function emitSiblingListFromArray(array $arr): void
    {
        $run = [];

        foreach ($arr as $child) {
            if (!$child instanceof Node) {
                if (count($run) >= 2) {
                    $this->siblingLists[] = new SiblingList($this->filePath, $run);
                }
                $run = [];
                continue;
            }

            $startLine = $child->getStartLine();
            $endLine = $child->getEndLine();

            if ($startLine === -1 || $endLine === -1) {
                if (count($run) >= 2) {
                    $this->siblingLists[] = new SiblingList($this->filePath, $run);
                }
                $run = [];
                continue;
            }

            $run[] = new Subtree(
                $this->filePath,
                $startLine,
                $endLine,
                $this->nodeCounts[$child] ?? throw new LogicException('Node without count: ' . $child::class),
                $this->getNodeHash($child),
            );
        }

        if (count($run) >= 2) {
            $this->siblingLists[] = new SiblingList($this->filePath, $run);
        }
    }

    private function getNodeHash(Node $node): string
    {
        if (isset($this->nodeHashes[$node])) {
            return $this->nodeHashes[$node];
        }

        $hash = $this->hasher->hashNode($node);
        $this->nodeHashes[$node] = $hash;
        return $hash;
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
