<?php declare(strict_types = 1);

namespace ShipMonk\CopyPasteDetector\Detection;

/**
 * Set of line numbers added/changed by a patch, indexed by absolute file path.
 */
final class ChangedLines
{

    /**
     * @param array<string, array<int, true>> $linesByFile absolute path => set of changed line numbers
     */
    public function __construct(
        private readonly array $linesByFile,
    )
    {
    }

    /**
     * Returns true iff every line in [startLine, endLine] is in the changed set for $filePath.
     */
    public function containsRange(
        string $filePath,
        int $startLine,
        int $endLine,
    ): bool
    {
        $lines = $this->linesByFile[$filePath] ?? null;

        if ($lines === null) {
            return false;
        }

        for ($line = $startLine; $line <= $endLine; $line++) {
            if (!isset($lines[$line])) {
                return false;
            }
        }

        return true;
    }

}
