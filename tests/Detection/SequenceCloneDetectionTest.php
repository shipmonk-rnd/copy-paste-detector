<?php declare(strict_types = 1);

namespace ShipMonk\CopyPasteDetectorTests\Detection;

use PHPUnit\Framework\TestCase;
use ShipMonk\CopyPasteDetector\Config\Config;
use ShipMonk\CopyPasteDetector\Detection\CloneDetector;
use ShipMonk\CopyPasteDetector\Detection\CloneGroup;
use function basename;
use function count;
use function file_put_contents;
use function mkdir;
use function rmdir;
use function sort;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

final class SequenceCloneDetectionTest extends TestCase
{

    /**
     * Sanity check: a copy-pasted *block of statements* embedded in two
     * otherwise-different methods has no enclosing AST node that matches
     * as a single subtree, but it is still detected via sequence-clone
     * detection.
     */
    public function testDetectsCopypastedSequenceEmbeddedInDifferentMethods(): void
    {
        $code1 = <<<'PHP'
        <?php
        function alpha($id) {
            initialize();
            $user = $repository->find($id);
            if ($user === null) {
                throw new NotFoundException();
            }
            $cache->set($cacheKey, $user);
            $logger->info("found");
            return $user;
        }
        PHP;

        $code2 = <<<'PHP'
        <?php
        function beta($input, $id) {
            validate($input);
            audit($input);
            $user = $repository->find($id);
            if ($user === null) {
                throw new NotFoundException();
            }
            $cache->set($cacheKey, $user);
            $logger->info("found");
            cleanup($input);
        }
        PHP;

        $groups = $this->runDetector($code1, $code2, minNodeCount: 30);

        self::assertGreaterThanOrEqual(1, count($groups), 'Expected at least one clone group');

        $found = false;
        foreach ($groups as $group) {
            if ($group->getInstanceCount() !== 2) {
                continue;
            }

            $files = [];
            foreach ($group->getSubtrees() as $subtree) {
                $files[] = basename($subtree->getFilePath());
            }
            sort($files);

            if ($files === ['file1.php', 'file2.php']) {
                $found = true;
                break;
            }
        }

        self::assertTrue($found, 'Expected a 2-instance cross-file clone group');
    }

    public function testDoesNotReportNonCloneCode(): void
    {
        $code1 = <<<'PHP'
        <?php
        function alpha() {
            $a = 1;
            $b = 2;
            return $a + $b;
        }
        PHP;

        $code2 = <<<'PHP'
        <?php
        function beta() {
            echo "totally different";
            do_other_thing();
            unrelated_call($x, $y, $z);
        }
        PHP;

        $groups = $this->runDetector($code1, $code2, minNodeCount: 10);

        self::assertSame([], $groups, 'Should not report clones between unrelated code');
    }

    public function testDisablingSequenceDetectionSilencesSequenceClones(): void
    {
        // Same fixture as testDetectsCopypastedSequenceEmbeddedInDifferentMethods,
        // but with sequence detection disabled — only whole-subtree clones remain.
        $code1 = <<<'PHP'
        <?php
        function alpha($id) {
            initialize();
            $user = $repository->find($id);
            if ($user === null) {
                throw new NotFoundException();
            }
            $cache->set($cacheKey, $user);
            $logger->info("found");
            return $user;
        }
        PHP;

        $code2 = <<<'PHP'
        <?php
        function beta($input, $id) {
            validate($input);
            audit($input);
            $user = $repository->find($id);
            if ($user === null) {
                throw new NotFoundException();
            }
            $cache->set($cacheKey, $user);
            $logger->info("found");
            cleanup($input);
        }
        PHP;

        $config = new Config();
        $config->setSequenceDetectionEnabled(false);

        $groups = $this->runDetectorWithConfig($code1, $code2, $config, minNodeCount: 30);

        // The if-block is the only single subtree large enough to register at minNodeCount=30,
        // and it has ~16 nodes which is below the threshold — so we expect zero clones.
        self::assertSame([], $groups);
    }

    /**
     * @return list<CloneGroup>
     */
    private function runDetector(
        string $code1,
        string $code2,
        int $minNodeCount,
    ): array
    {
        return $this->runDetectorWithConfig($code1, $code2, new Config(), $minNodeCount);
    }

    /**
     * @return list<CloneGroup>
     */
    private function runDetectorWithConfig(
        string $code1,
        string $code2,
        Config $config,
        int $minNodeCount,
    ): array
    {
        $tempDir = sys_get_temp_dir() . '/cpd-seq-test-' . uniqid();
        mkdir($tempDir);

        $file1 = $tempDir . '/file1.php';
        $file2 = $tempDir . '/file2.php';

        file_put_contents($file1, $code1);
        file_put_contents($file2, $code2);

        try {
            $detector = new CloneDetector($config);
            return $detector->detect([$file1, $file2], $minNodeCount, null, null);
        } finally {
            unlink($file1);
            unlink($file2);
            rmdir($tempDir);
        }
    }

}
