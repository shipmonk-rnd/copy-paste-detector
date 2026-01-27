<?php declare(strict_types = 1);

namespace ShipMonk\CopyPasteDetector\Hashing;

use PhpParser\Node;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\DNumber;
use PhpParser\Node\Scalar\LNumber;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeVisitorAbstract;

/**
 * Visitor that normalizes AST nodes by anonymizing identifiers and literals
 * Used during AST normalization for Type-2 clone detection
 */
final class NormalizingVisitor extends NodeVisitorAbstract
{

    private int $varCounter = 0;
    private int $literalCounter = 0;
    private int $nameCounter = 0;

    public function __construct(
        private readonly bool $anonymizeVariables,
        private readonly bool $anonymizeLiterals,
        private readonly bool $anonymizeNames,
        private readonly bool $anonymizeIdentifiers,
    )
    {
    }

    public function leaveNode(Node $node): ?Node
    {
        // Anonymize variable names
        if ($this->anonymizeVariables && $node instanceof Variable) {
            // Replace variable name with V<counter>
            $node->name = 'V' . $this->varCounter++;
            return $node;
        }

        // Anonymize scalar literals (strings, integers, floats, etc.)
        if ($this->anonymizeLiterals) {
            if ($node instanceof String_) {
                $node->value = 'S' . $this->literalCounter++;
                return $node;
            }

            if ($node instanceof LNumber) {
                $node->value = $this->literalCounter++;
                return $node;
            }

            if ($node instanceof DNumber) {
                $node->value = (float) $this->literalCounter++;
                return $node;
            }
        }

        // Anonymize names (function names, class names, etc.)
        if ($this->anonymizeNames && $node instanceof Name) {
            // Create a new Name node with anonymized name
            return new Name(['N' . $this->nameCounter++]);
        }

        // Anonymize identifiers (method names, constant names, etc.)
        if ($this->anonymizeIdentifiers && $node instanceof Identifier) {
            $node->name = 'I' . $this->nameCounter++;
            return $node;
        }

        return null;
    }

}
