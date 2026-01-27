<?php declare(strict_types = 1);

namespace CopyPasteDetector\AST;

/**
 * Represents a subtree extracted from an AST with metadata
 */
final class Subtree
{

    public function __construct(
        private readonly string $filePath,
        private readonly int $startLine,
        private readonly int $endLine,
        private readonly int $nodeCount,
        private readonly string $hash,
    )
    {
    }

    public function getFilePath(): string
    {
        return $this->filePath;
    }

    public function getStartLine(): int
    {
        return $this->startLine;
    }

    public function getEndLine(): int
    {
        return $this->endLine;
    }

    public function getNodeCount(): int
    {
        return $this->nodeCount;
    }

    /**
     * Get the structural hash of this subtree
     */
    public function getHash(): string
    {
        return $this->hash;
    }

}
