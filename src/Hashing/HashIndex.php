<?php declare(strict_types = 1);

namespace CopyPasteDetector\Hashing;

use CopyPasteDetector\AST\Subtree;
use function array_filter;
use function count;

/**
 * Hash-based index for finding exact structural clones
 *
 * Groups subtrees by their MD5 fingerprint. All subtrees with the same hash
 * are structural clones (Type-1 or Type-2 depending on normalization)
 *
 * This is the CloneDR approach - much simpler and faster than LSH,
 * but only finds exact matches (no Type-3 near-miss detection)
 */
final class HashIndex
{

    /**
     * Map of hash => array of Subtree objects
     *
     * @var array<string, list<Subtree>>
     */
    private array $hashTable = [];

    /**
     * Index a single subtree
     *
     * @param Subtree $subtree The subtree to index
     */
    public function index(Subtree $subtree): void
    {
        $hash = $subtree->getHash();

        if (!isset($this->hashTable[$hash])) {
            $this->hashTable[$hash] = [];
        }

        $this->hashTable[$hash][] = $subtree;
    }

    /**
     * Get all clone groups (groups of 2+ subtrees with identical structure)
     *
     * @return array<string, list<Subtree>> Map of hash => array of clone subtrees
     */
    public function getCloneGroups(): array
    {
        // Filter to only groups with 2+ subtrees (i.e., actual clones)
        return array_filter(
            $this->hashTable,
            static fn (array $subtrees) => count($subtrees) >= 2,
        );
    }

}
