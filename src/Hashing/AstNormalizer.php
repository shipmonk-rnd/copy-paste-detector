<?php declare(strict_types = 1);

namespace ShipMonk\CopyPasteDetector\Hashing;

use LogicException;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use function array_key_exists;

/**
 * Normalizes AST subtrees by anonymizing identifiers and literals
 * This makes the AST structure-only, suitable for Type-2 clone detection
 *
 * The normalization is deterministic: the same structure will always produce
 * the same normalized form, regardless of original variable names or literal values
 */
final class AstNormalizer
{

    public function __construct(
        private readonly bool $anonymizeVariables,
        private readonly bool $anonymizeLiterals,
        private readonly bool $anonymizeNames,
        private readonly bool $anonymizeIdentifiers,
    )
    {
    }

    /**
     * Normalize an AST subtree by replacing all identifiers and literals
     * with anonymous placeholders based on their position
     *
     * @param Node $root Root node of the subtree to normalize
     * @return Node Cloned and normalized AST (original is not modified)
     */
    public function normalize(Node $root): Node
    {
        // Deep clone the AST so we don't modify the original
        $cloningTraverser = new NodeTraverser();
        $cloningTraverser->addVisitor(new CloningVisitor());
        $clonedNodes = $cloningTraverser->traverse([$root]);

        if (!array_key_exists(0, $clonedNodes)) {
            throw new LogicException('Cloning traverser must return at least one node');
        }

        $clonedRoot = $clonedNodes[0];

        $visitor = new NormalizingVisitor(
            $this->anonymizeVariables,
            $this->anonymizeLiterals,
            $this->anonymizeNames,
            $this->anonymizeIdentifiers,
        );

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $normalizedNodes = $traverser->traverse([$clonedRoot]);

        if (!array_key_exists(0, $normalizedNodes)) {
            throw new LogicException('Normalizing traverser must return at least one node');
        }

        return $normalizedNodes[0];
    }

}
