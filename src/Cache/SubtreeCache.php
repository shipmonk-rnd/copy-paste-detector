<?php declare(strict_types = 1);

namespace ShipMonk\CopyPasteDetector\Cache;

use JsonException;
use LogicException;
use ShipMonk\CopyPasteDetector\AST\ExtractionResult;
use ShipMonk\CopyPasteDetector\AST\SiblingList;
use ShipMonk\CopyPasteDetector\AST\Subtree;
use ShipMonk\CopyPasteDetector\Config\AnonymizationSettings;
use function array_map;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function is_array;
use function is_dir;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;
use function md5;
use function md5_file;
use function mkdir;
use function serialize;
use const JSON_THROW_ON_ERROR;

/**
 * Caches parsed subtrees (and sibling-stmt lists used by sequence-clone
 * detection) to avoid re-parsing unchanged files.
 */
final class SubtreeCache
{

    private string $cacheDir;
    private AnonymizationSettings $anonymizationSettings;

    public function __construct(
        string $cacheDir,
        AnonymizationSettings $anonymizationSettings,
    )
    {
        $this->cacheDir = $cacheDir;
        $this->anonymizationSettings = $anonymizationSettings;

        if (!is_dir($this->cacheDir)) {
            if (!mkdir($this->cacheDir, 0755, true) && !is_dir($this->cacheDir)) {
                throw new LogicException("Failed to create cache directory '{$this->cacheDir}'");
            }
        }
    }

    /**
     * @param string $filePath Path to the source file
     * @param int $minNodeCount Minimum node count threshold used for extraction
     * @return ExtractionResult|null Cached result if cache is valid, null otherwise
     */
    public function get(
        string $filePath,
        int $minNodeCount,
    ): ?ExtractionResult
    {
        if (!file_exists($filePath)) {
            return null;
        }

        $cacheFile = $this->getCacheFilePath($filePath, $minNodeCount);
        if (!file_exists($cacheFile)) {
            return null;
        }

        $currentHash = $this->getFileHash($filePath);
        $cacheData = file_get_contents($cacheFile);
        if ($cacheData === false) {
            throw new LogicException("Failed to read cache file '{$cacheFile}'");
        }

        try {
            $cached = json_decode($cacheData, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new LogicException("Failed to decode cache file '{$cacheFile}': {$e->getMessage()}", 0, $e);
        }

        if (
            !is_array($cached)
            || !isset($cached['hash'], $cached['subtrees'], $cached['siblingLists'])
            || !is_array($cached['subtrees'])
            || !is_array($cached['siblingLists'])
        ) {
            return null;
        }

        if ($cached['hash'] !== $currentHash) {
            return null;
        }

        $subtrees = $this->deserializeSubtrees($cached['subtrees'], $filePath);
        if ($subtrees === null) {
            return null;
        }

        $siblingLists = $this->deserializeSiblingLists($cached['siblingLists'], $filePath);
        if ($siblingLists === null) {
            return null;
        }

        return new ExtractionResult($subtrees, $siblingLists);
    }

    public function set(
        string $filePath,
        int $minNodeCount,
        ExtractionResult $result,
    ): void
    {
        if (!file_exists($filePath)) {
            return;
        }

        $cacheFile = $this->getCacheFilePath($filePath, $minNodeCount);
        $fileHash = $this->getFileHash($filePath);

        $cacheData = [
            'hash' => $fileHash,
            'subtrees' => $this->serializeSubtrees($result->getSubtrees()),
            'siblingLists' => $this->serializeSiblingLists($result->getSiblingLists()),
        ];

        try {
            $json = json_encode($cacheData, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new LogicException("Failed to encode cache data: {$e->getMessage()}", 0, $e);
        }

        $written = file_put_contents($cacheFile, $json);
        if ($written === false) {
            throw new LogicException("Failed to write cache file '{$cacheFile}'");
        }
    }

    /**
     * @param list<Subtree> $subtrees
     * @return list<array{startLine: int, endLine: int, nodeCount: int, hash: string}>
     */
    private function serializeSubtrees(array $subtrees): array
    {
        // filePath is omitted - we know it from the cache key context.
        return array_map(
            static fn (Subtree $subtree): array => [
                'startLine' => $subtree->getStartLine(),
                'endLine' => $subtree->getEndLine(),
                'nodeCount' => $subtree->getNodeCount(),
                'hash' => $subtree->getHash(),
            ],
            $subtrees,
        );
    }

    /**
     * @param array<mixed> $data
     * @return list<Subtree>|null
     */
    private function deserializeSubtrees(
        array $data,
        string $filePath,
    ): ?array
    {
        $subtrees = [];

        foreach ($data as $item) {
            $subtree = $this->deserializeSubtree($item, $filePath);
            if ($subtree === null) {
                return null;
            }
            $subtrees[] = $subtree;
        }

        return $subtrees;
    }

    /**
     * @param list<SiblingList> $siblingLists
     * @return list<list<array{startLine: int, endLine: int, nodeCount: int, hash: string}>>
     */
    private function serializeSiblingLists(array $siblingLists): array
    {
        return array_map(
            fn (SiblingList $list): array => $this->serializeSubtrees($list->getStmts()),
            $siblingLists,
        );
    }

    /**
     * @param array<mixed> $data
     * @return list<SiblingList>|null
     */
    private function deserializeSiblingLists(
        array $data,
        string $filePath,
    ): ?array
    {
        $siblingLists = [];

        foreach ($data as $item) {
            if (!is_array($item)) {
                return null;
            }

            $stmts = [];
            foreach ($item as $stmtItem) {
                $stmt = $this->deserializeSubtree($stmtItem, $filePath);
                if ($stmt === null) {
                    return null;
                }
                $stmts[] = $stmt;
            }

            $siblingLists[] = new SiblingList($filePath, $stmts);
        }

        return $siblingLists;
    }

    /**
     * @param mixed $item
     */
    private function deserializeSubtree(
        $item,
        string $filePath,
    ): ?Subtree
    {
        if (
            !is_array($item)
            || !isset($item['startLine'], $item['endLine'], $item['nodeCount'], $item['hash'])
            || !is_int($item['startLine'])
            || !is_int($item['endLine'])
            || !is_int($item['nodeCount'])
            || !is_string($item['hash'])
        ) {
            return null;
        }

        return new Subtree(
            $filePath,
            $item['startLine'],
            $item['endLine'],
            $item['nodeCount'],
            $item['hash'],
        );
    }

    private function getCacheFilePath(
        string $filePath,
        int $minNodeCount,
    ): string
    {
        $settings = $this->anonymizationSettings;

        $key = md5(serialize([
            'file' => $filePath,
            'minNodeCount' => $minNodeCount,
            'anonymizeVariables' => $settings->variables,
            'anonymizeLiterals' => $settings->literals,
            'anonymizeNames' => $settings->names,
            'anonymizeIdentifiers' => $settings->identifiers,
        ]));

        return $this->cacheDir . '/' . $key . '.cache';
    }

    private function getFileHash(string $filePath): string
    {
        $hash = md5_file($filePath);
        if ($hash === false) {
            throw new LogicException("Failed to compute hash for file '{$filePath}'");
        }
        return $hash;
    }

}
