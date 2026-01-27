<?php declare(strict_types = 1);

namespace ShipMonk\CopyPasteDetectorTests\Detection;

use PHPUnit\Framework\TestCase;
use ShipMonk\CopyPasteDetector\Config\Config;
use ShipMonk\CopyPasteDetector\Detection\CloneDetector;
use ShipMonk\CopyPasteDetector\Detection\CloneGroup;
use function file_put_contents;
use function mkdir;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

final class CloneDetectionTest extends TestCase
{

    private CloneDetector $detector;
    private string $fixturesPath;

    protected function setUp(): void
    {
        $this->detector = new CloneDetector($this->createConfig());
        $this->fixturesPath = __DIR__ . '/../_fixtures/sample_code';
    }

    private function createConfig(): Config
    {
        return new Config();
    }

    /**
     * @param list<string> $files
     * @return list<CloneGroup>
     */
    private function detect(
        array $files,
        int $minNodeCount,
    ): array
    {
        return $this->detector->detect($files, $minNodeCount, null, null);
    }

    public function testDetectClonesInSampleFiles(): void
    {
        $files = [
            $this->fixturesPath . '/Calculator1.php',
            $this->fixturesPath . '/Calculator2.php',
        ];

        // Use lower threshold for testing
        $cloneGroups = $this->detect($files, minNodeCount: 10);

        // We expect to find clone groups between the similar methods
        self::assertNotEmpty($cloneGroups, 'Should detect clone groups in sample files');

        foreach ($cloneGroups as $group) {
            // Each group should have at least 2 instances
            self::assertGreaterThanOrEqual(2, $group->getInstanceCount());

            // All subtrees in a group should have the same node count
            $nodeCount = $group->getNodeCount();
            foreach ($group->getSubtrees() as $subtree) {
                self::assertSame($nodeCount, $subtree->getNodeCount());
            }
        }
    }

    public function testNoFalsePositivesWithDifferentCode(): void
    {
        // Create temporary files with completely different code
        $tempDir = sys_get_temp_dir() . '/clone-detector-test-' . uniqid();
        mkdir($tempDir);

        $file1 = $tempDir . '/different1.php';
        $file2 = $tempDir . '/different2.php';

        file_put_contents($file1, '<?php function alpha() { return 1; }');
        file_put_contents($file2, '<?php class Beta { public $x; }');

        try {
            $cloneGroups = $this->detect([$file1, $file2], minNodeCount: 5);

            // Completely different code should not produce clones
            self::assertEmpty($cloneGroups, 'Should not detect clones in completely different code');
        } finally {
            // Cleanup
            unlink($file1);
            unlink($file2);
            rmdir($tempDir);
        }
    }

    public function testParameterizationWithDifferentVariableNames(): void
    {
        // Create temporary files with identical structure but different variable names
        $tempDir = sys_get_temp_dir() . '/clone-detector-test-' . uniqid();
        mkdir($tempDir);

        $file1 = $tempDir . '/param1.php';
        $file2 = $tempDir . '/param2.php';

        $code1 = '<?php
            function processItems($items) {
                $result = 0;
                foreach ($items as $item) {
                    if ($item > 10) {
                        $result += $item * 2;
                    }
                }
                return $result;
            }
        ';

        $code2 = '<?php
            function calculateValues($values) {
                $total = 0;
                foreach ($values as $value) {
                    if ($value > 10) {
                        $total += $value * 2;
                    }
                }
                return $total;
            }
        ';

        file_put_contents($file1, $code1);
        file_put_contents($file2, $code2);

        try {
            $cloneGroups = $this->detect([$file1, $file2], minNodeCount: 10);

            // Should detect these as clones due to normalization (anonymization)
            self::assertNotEmpty(
                $cloneGroups,
                'Should detect clones despite different variable names (normalization)',
            );

            // All detected groups should have at least 2 instances
            foreach ($cloneGroups as $group) {
                self::assertGreaterThanOrEqual(2, $group->getInstanceCount());
            }
        } finally {
            // Cleanup
            unlink($file1);
            unlink($file2);
            rmdir($tempDir);
        }
    }

}
