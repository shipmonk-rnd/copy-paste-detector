<?php declare(strict_types = 1);

namespace ShipMonk\CopyPasteDetector\AST;

/**
 * A contiguous list of sibling-statement subtrees extracted from a single
 * `array<Node>` member of some parent AST node (e.g. a method body, a
 * `foreach` body, a class body…).
 *
 * Used as input to sequence-based clone detection: while individual statements
 * may be too small to register as whole-subtree clones, a long contiguous
 * sequence of them shared between files is still a meaningful clone.
 */
final class SiblingList
{

    /**
     * @param list<Subtree> $stmts Per-statement subtrees, in source order.
     */
    public function __construct(
        private readonly string $filePath,
        private readonly array $stmts,
    )
    {
    }

    public function getFilePath(): string
    {
        return $this->filePath;
    }

    /**
     * @return list<Subtree>
     */
    public function getStmts(): array
    {
        return $this->stmts;
    }

}
