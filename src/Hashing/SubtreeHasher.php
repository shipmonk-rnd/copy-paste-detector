<?php declare(strict_types = 1);

namespace CopyPasteDetector\Hashing;

use PhpParser\Node;
use function implode;
use function is_array;
use function is_scalar;
use function md5;
use function var_export;

/**
 * Computes MD5 fingerprints of normalized AST subtrees
 *
 * This implements the CloneDR-style approach:
 * 1. Normalize the subtree (anonymize identifiers/literals)
 * 2. Serialize the normalized AST to a canonical string
 * 3. Hash the string with MD5
 *
 * Subtrees with identical structure will have identical hashes,
 * regardless of variable names or literal values (Type-2 clones)
 */
final class SubtreeHasher
{

    public function __construct(
        private readonly AstNormalizer $normalizer,
    )
    {
    }

    /**
     * Compute the hash fingerprint of a node
     *
     * @param Node $node The node to hash
     * @return string 32-character MD5 hash
     */
    public function hashNode(Node $node): string
    {
        // Step 1: Normalize the AST (anonymize identifiers and literals)
        $normalizedNode = $this->normalizer->normalize($node);

        // Step 2: Serialize to canonical string representation
        // PHP's serialize() is deterministic and includes full structure
        $serialized = $this->serializeNode($normalizedNode);

        // Step 3: Hash with MD5
        return md5($serialized);
    }

    /**
     * Serialize a node to a canonical string representation
     *
     * Uses a custom serialization that only includes structural information,
     * not line numbers or other metadata that may differ between clones
     *
     * @param Node $node The node to serialize
     * @return string Canonical string representation
     */
    private function serializeNode(Node $node): string
    {
        // Build a structural representation recursively
        $parts = [$node::class];

        // Serialize all node attributes (but skip position/line metadata)
        foreach ($node->getSubNodeNames() as $name) {
            /** @phpstan-ignore property.dynamicName */
            $value = $node->$name;

            if ($value === null) {
                $parts[] = 'null';
            } elseif (is_scalar($value)) {
                $parts[] = "$name:" . var_export($value, true);
            } elseif ($value instanceof Node) {
                $parts[] = "$name:" . $this->serializeNode($value);
            } elseif (is_array($value)) {
                $arrayParts = [];
                foreach ($value as $item) {
                    if ($item instanceof Node) {
                        $arrayParts[] = $this->serializeNode($item);
                    } elseif (is_scalar($item)) {
                        $arrayParts[] = var_export($item, true);
                    }
                }
                $parts[] = "$name:[" . implode(',', $arrayParts) . ']';
            }
        }

        return implode('|', $parts);
    }

}
