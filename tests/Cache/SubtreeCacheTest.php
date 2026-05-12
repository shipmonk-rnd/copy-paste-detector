<?php declare(strict_types = 1);

namespace ShipMonk\CopyPasteDetectorTests\Cache;

use PHPUnit\Framework\TestCase;
use ShipMonk\CopyPasteDetector\AST\ExtractionResult;
use ShipMonk\CopyPasteDetector\AST\Parser;
use ShipMonk\CopyPasteDetector\AST\SubtreeExtractor;
use ShipMonk\CopyPasteDetector\Cache\SubtreeCache;
use ShipMonk\CopyPasteDetector\Config\AnonymizationSettings;
use ShipMonk\CopyPasteDetector\Hashing\AstNormalizer;
use ShipMonk\CopyPasteDetector\Hashing\SubtreeHasher;
use ShipMonk\CopyPasteDetectorTests\Helpers\TestDirectoryHelper;
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

        new SubtreeCache($this->cacheDir, $this->createDefaultSettings());

        self::assertDirectoryExists($this->cacheDir);
    }

    public function testGetReturnsNullForNonExistentFile(): void
    {
        $cache = new SubtreeCache($this->cacheDir, $this->createDefaultSettings());

        $result = $cache->get('/nonexistent/file.php', 10);

        self::assertNull($result);
    }

    public function testGetReturnsNullWhenNoCacheExists(): void
    {
        $cache = new SubtreeCache($this->cacheDir, $this->createDefaultSettings());
        $file = $this->createTempFile('<?php $x = 1;');

        $result = $cache->get($file, 10);

        self::assertNull($result);
    }

    public function testSetAndGetRoundTrip(): void
    {
        $cache = new SubtreeCache($this->cacheDir, $this->createDefaultSettings());
        $file = $this->createTempFile('<?php
            function calculate($a, $b) {
                $result = $a + $b;
                return $result * 2;
            }
        ');

        $parser = new Parser();
        $extractor = $this->createSubtreeExtractor();
        $ast = $parser->parseFile($file);
        $extracted = $extractor->extract($ast, $file, minNodeCount: 5);

        $cache->set($file, 5, $extracted);
        $cached = $cache->get($file, 5);

        self::assertNotNull($cached);

        $originalSubtrees = $extracted->getSubtrees();
        $cachedSubtrees = $cached->getSubtrees();
        self::assertCount(count($originalSubtrees), $cachedSubtrees);

        foreach ($cachedSubtrees as $i => $subtree) {
            self::assertArrayHasKey($i, $originalSubtrees);
            self::assertSame($originalSubtrees[$i]->getFilePath(), $subtree->getFilePath());
            self::assertSame($originalSubtrees[$i]->getNodeCount(), $subtree->getNodeCount());
            self::assertSame($originalSubtrees[$i]->getHash(), $subtree->getHash());
        }

        self::assertCount(count($extracted->getSiblingLists()), $cached->getSiblingLists());
    }

    public function testCacheInvalidatedWhenFileChanges(): void
    {
        $cache = new SubtreeCache($this->cacheDir, $this->createDefaultSettings());
        $file = $this->createTempFile('<?php $x = 1;');

        $parser = new Parser();
        $extractor = $this->createSubtreeExtractor();
        $ast = $parser->parseFile($file);
        $extracted = $extractor->extract($ast, $file, minNodeCount: 1);

        $cache->set($file, 1, $extracted);

        self::assertNotNull($cache->get($file, 1));

        file_put_contents($file, '<?php $y = 2; $z = 3;');

        self::assertNull($cache->get($file, 1));
    }

    public function testDifferentMinNodeCountUsesSeparateCache(): void
    {
        $cache = new SubtreeCache($this->cacheDir, $this->createDefaultSettings());
        $file = $this->createTempFile('<?php
            function foo($x) {
                $y = $x + 1;
                return $y * 2;
            }
        ');

        $parser = new Parser();
        $extractor = $this->createSubtreeExtractor();
        $ast = $parser->parseFile($file);
        $extracted5 = $extractor->extract($ast, $file, minNodeCount: 5);
        $extracted10 = $extractor->extract($ast, $file, minNodeCount: 10);

        $cache->set($file, 5, $extracted5);
        $cache->set($file, 10, $extracted10);

        $cached5 = $cache->get($file, 5);
        $cached10 = $cache->get($file, 10);

        self::assertNotNull($cached5);
        self::assertNotNull($cached10);
        self::assertCount(count($extracted5->getSubtrees()), $cached5->getSubtrees());
        self::assertCount(count($extracted10->getSubtrees()), $cached10->getSubtrees());
    }

    public function testDifferentAnonymizationSettingsUseSeparateCache(): void
    {
        $file = $this->createTempFile('<?php
            function foo($x) {
                $y = $x + 1;
                return $y * 2;
            }
        ');

        $parser = new Parser();
        $extractor = $this->createSubtreeExtractor();
        $ast = $parser->parseFile($file);
        $extracted = $extractor->extract($ast, $file, minNodeCount: 5);

        $settings1 = new AnonymizationSettings(
            variables: true,
            literals: false,
            names: false,
            identifiers: false,
        );
        $cache1 = new SubtreeCache($this->cacheDir, $settings1);
        $cache1->set($file, 5, $extracted);

        self::assertNotNull($cache1->get($file, 5));

        $settings2 = new AnonymizationSettings(
            variables: true,
            literals: true,
            names: false,
            identifiers: false,
        );
        $cache2 = new SubtreeCache($this->cacheDir, $settings2);
        self::assertNull($cache2->get($file, 5));
    }

    public function testSetIgnoresNonExistentFile(): void
    {
        $cache = new SubtreeCache($this->cacheDir, $this->createDefaultSettings());

        $cache->set('/nonexistent/file.php', 10, new ExtractionResult([], []));

        self::assertNull($cache->get('/nonexistent/file.php', 10));
    }

    private function createDefaultSettings(): AnonymizationSettings
    {
        return new AnonymizationSettings(
            variables: true,
            literals: false,
            names: false,
            identifiers: false,
        );
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
