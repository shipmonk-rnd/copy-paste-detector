<?php declare(strict_types = 1);

namespace ShipMonk\CopyPasteDetector\Cache;

use JsonException;
use LogicException;
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
 * Caches parsed subtrees to avoid re-parsing unchanged files
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
     * Get cached subtrees for a file if cache is valid
     *
     * @param string $filePath Path to the source file
     * @param int $minNodeCount Minimum node count threshold used for extraction
     * @return list<Subtree>|null Array of subtrees if cache is valid, null otherwise
     */
    public function get(
        string $filePath,
        int $minNodeCount,
    ): ?array
    {
        if (!file_exists($filePath)) {
            return null;
        }

        $cacheFile = $this->getCacheFilePath($filePath, $minNodeCount);
        if (!file_exists($cacheFile)) {
            return null;
        }

        // Check if cache is still valid (file hasn't changed)
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

        if (!is_array($cached) || !isset($cached['hash']) || !isset($cached['subtrees']) || !is_array($cached['subtrees'])) {
            return null; // Invalid cache format, treat as cache miss
        }

        // Validate cache is for same file version
        if ($cached['hash'] !== $currentHash) {
            return null;
        }

        return $this->deserializeSubtrees($cached['subtrees']);
    }

    /**
     * Store subtrees in cache
     *
     * @param string $filePath Path to the source file
     * @param int $minNodeCount Minimum node count threshold used for extraction
     * @param list<Subtree> $subtrees Extracted subtrees to cache
     */
    public function set(
        string $filePath,
        int $minNodeCount,
        array $subtrees,
    ): void
    {
        if (!file_exists($filePath)) {
            return;
        }

        $cacheFile = $this->getCacheFilePath($filePath, $minNodeCount);
        $fileHash = $this->getFileHash($filePath);

        $cacheData = [
            'hash' => $fileHash,
            'subtrees' => $this->serializeSubtrees($subtrees),
        ];

        try {
            $json = json_encode($cacheData, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new LogicException("Failed to encode cache data: {$e->getMessage()}", 0, $e);
        }

        $result = file_put_contents($cacheFile, $json);
        if ($result === false) {
            throw new LogicException("Failed to write cache file '{$cacheFile}'");
        }
    }

    /**
     * @param list<Subtree> $subtrees
     * @return list<array{filePath: string, startLine: int, endLine: int, nodeCount: int, hash: string}>
     */
    private function serializeSubtrees(array $subtrees): array
    {
        return array_map(
            static fn (Subtree $subtree): array => [
                'filePath' => $subtree->getFilePath(),
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
    private function deserializeSubtrees(array $data): ?array
    {
        $subtrees = [];

        foreach ($data as $item) {
            if (
                !is_array($item)
                || !isset($item['filePath'], $item['startLine'], $item['endLine'], $item['nodeCount'], $item['hash'])
                || !is_string($item['filePath'])
                || !is_int($item['startLine'])
                || !is_int($item['endLine'])
                || !is_int($item['nodeCount'])
                || !is_string($item['hash'])
            ) {
                return null; // Invalid data, treat as cache miss
            }

            $subtrees[] = new Subtree(
                $item['filePath'],
                $item['startLine'],
                $item['endLine'],
                $item['nodeCount'],
                $item['hash'],
            );
        }

        return $subtrees;
    }

    /**
     * Get cache file path for a source file, minNodeCount, and anonymization settings
     */
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

    /**
     * Get hash of file contents
     */
    private function getFileHash(string $filePath): string
    {
        $hash = md5_file($filePath);
        if ($hash === false) {
            throw new LogicException("Failed to compute hash for file '{$filePath}'");
        }
        return $hash;
    }

}
