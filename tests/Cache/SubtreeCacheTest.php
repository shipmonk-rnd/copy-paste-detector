<?php declare(strict_types = 1);

namespace CopyPasteDetector\Tests\Cache;

use CopyPasteDetector\AST\Parser;
use CopyPasteDetector\AST\SubtreeExtractor;
use CopyPasteDetector\Cache\SubtreeCache;
use CopyPasteDetector\Hashing\AstNormalizer;
use CopyPasteDetector\Hashing\SubtreeHasher;
use CopyPasteDetector\Tests\Helpers\TestDirectoryHelper;
use PHPUnit\Framework\TestCase;
use function count;
use function file_put_contents;
use function mkdir;
use function sys_get_temp_dir;
use function uniqid;

final class SubtreeCacheTest extends TestCase
{

    private string $tempDir;
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/cpd-test-' . uniqid();
        $this->cacheDir = $this->tempDir . '/cache';
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        TestDirectoryHelper::removeDirectory($this->tempDir);
    }

    public function testCacheDirectoryCreatedAutomatically(): void
    {
        self::assertDirectoryDoesNotExist($this->cacheDir);

        new SubtreeCache($this->cacheDir);

        self::assertDirectoryExists($this->cacheDir);
    }

    public function testGetReturnsNullForNonExistentFile(): void
    {
        $cache = new SubtreeCache($this->cacheDir);

        $result = $cache->get('/nonexistent/file.php', 10);

        self::assertNull($result);
    }

    public function testGetReturnsNullWhenNoCacheExists(): void
    {
        $cache = new SubtreeCache($this->cacheDir);
        $file = $this->createTempFile('<?php $x = 1;');

        $result = $cache->get($file, 10);

        self::assertNull($result);
    }

    public function testSetAndGetRoundTrip(): void
    {
        $cache = new SubtreeCache($this->cacheDir);
        $file = $this->createTempFile('<?php
            function calculate($a, $b) {
                $result = $a + $b;
                return $result * 2;
            }
        ');

        // Parse and extract subtrees from real code
        $parser = new Parser();
        $extractor = $this->createSubtreeExtractor();
        $ast = $parser->parseFile($file);
        $subtrees = $extractor->extract($ast, $file, minNodeCount: 5);

        // Store in cache
        $cache->set($file, 5, $subtrees);

        // Retrieve from cache
        $cached = $cache->get($file, 5);

        self::assertNotNull($cached);
        self::assertCount(count($subtrees), $cached);

        // Verify subtree data integrity
        foreach ($cached as $i => $subtree) {
            self::assertArrayHasKey($i, $subtrees);
            self::assertSame($subtrees[$i]->getFilePath(), $subtree->getFilePath());
            self::assertSame($subtrees[$i]->getNodeCount(), $subtree->getNodeCount());
            self::assertSame($subtrees[$i]->getHash(), $subtree->getHash());
        }
    }

    public function testCacheInvalidatedWhenFileChanges(): void
    {
        $cache = new SubtreeCache($this->cacheDir);
        $file = $this->createTempFile('<?php $x = 1;');

        $parser = new Parser();
        $extractor = $this->createSubtreeExtractor();
        $ast = $parser->parseFile($file);
        $subtrees = $extractor->extract($ast, $file, minNodeCount: 1);

        // Store in cache
        $cache->set($file, 1, $subtrees);

        // Verify cache hit
        self::assertNotNull($cache->get($file, 1));

        // Modify file content
        file_put_contents($file, '<?php $y = 2; $z = 3;');

        // Cache should be invalidated
        self::assertNull($cache->get($file, 1));
    }

    public function testDifferentMinNodeCountUsesSeparateCache(): void
    {
        $cache = new SubtreeCache($this->cacheDir);
        $file = $this->createTempFile('<?php
            function foo($x) {
                $y = $x + 1;
                return $y * 2;
            }
        ');

        $parser = new Parser();
        $extractor = $this->createSubtreeExtractor();
        $ast = $parser->parseFile($file);
        $subtrees5 = $extractor->extract($ast, $file, minNodeCount: 5);
        $subtrees10 = $extractor->extract($ast, $file, minNodeCount: 10);

        // Store with different minNodeCount values
        $cache->set($file, 5, $subtrees5);
        $cache->set($file, 10, $subtrees10);

        // Retrieve separately
        $cached5 = $cache->get($file, 5);
        $cached10 = $cache->get($file, 10);

        self::assertNotNull($cached5);
        self::assertNotNull($cached10);
        self::assertCount(count($subtrees5), $cached5);
        self::assertCount(count($subtrees10), $cached10);
    }

    public function testSetIgnoresNonExistentFile(): void
    {
        $cache = new SubtreeCache($this->cacheDir);

        // Should not throw, just silently ignore
        $cache->set('/nonexistent/file.php', 10, []);

        // No cache should be created
        self::assertNull($cache->get('/nonexistent/file.php', 10));
    }

    private function createTempFile(string $content): string
    {
        $file = $this->tempDir . '/' . uniqid() . '.php';
        file_put_contents($file, $content);
        return $file;
    }

    private function createSubtreeExtractor(): SubtreeExtractor
    {
        $hasher = new SubtreeHasher(new AstNormalizer(true, false, false, false));
        return new SubtreeExtractor($hasher);
    }

}
