<?php declare(strict_types = 1);

namespace CopyPasteDetector\Cache;

use CopyPasteDetector\AST\Subtree;
use LogicException;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function is_array;
use function is_dir;
use function md5;
use function md5_file;
use function mkdir;
use function serialize;
use function sys_get_temp_dir;
use function unserialize;

/**
 * Caches parsed subtrees to avoid re-parsing unchanged files
 */
final class SubtreeCache
{

    private string $cacheDir;

    public function __construct(
        ?string $cacheDir = null,
    )
    {
        $this->cacheDir = $cacheDir ?? sys_get_temp_dir() . '/copy-paste-detector-cache';

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

        $cached = unserialize($cacheData);
        if (!is_array($cached) || !isset($cached['hash']) || !isset($cached['subtrees'])) {
            throw new LogicException("Cache file '{$cacheFile}' contains invalid data");
        }

        // Validate cache is for same file version
        if ($cached['hash'] !== $currentHash) {
            return null;
        }

        /** @var list<Subtree> $subtrees */
        $subtrees = $cached['subtrees'];
        return $subtrees;
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
            'subtrees' => $subtrees,
        ];

        $result = file_put_contents($cacheFile, serialize($cacheData));
        if ($result === false) {
            throw new LogicException("Failed to write cache file '{$cacheFile}'");
        }
    }

    /**
     * Get cache file path for a source file and minNodeCount combination
     */
    private function getCacheFilePath(
        string $filePath,
        int $minNodeCount,
    ): string
    {
        // Create a unique cache key based on file path and minNodeCount
        $key = md5($filePath . ':' . $minNodeCount);
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
