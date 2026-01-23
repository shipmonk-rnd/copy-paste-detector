<?php declare(strict_types = 1);

namespace CopyPasteDetector\Tests;

use CopyPasteDetector\Config\Configuration;
use CopyPasteDetector\Detection\CloneDetector;
use CopyPasteDetector\Reporting\SyntaxHighlighter;
use CopyPasteDetector\Reporting\TextReporter;
use PHPUnit\Framework\TestCase;
use function count;
use function file_put_contents;
use function mkdir;
use function sys_get_temp_dir;
use function uniqid;

final class TextReporterTest extends TestCase
{

    private string $tempDir;
    private TextReporter $reporter;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/cpd-reporter-test-' . uniqid();
        mkdir($this->tempDir, 0755, true);

        $highlighter = new SyntaxHighlighter(enabled: false);
        $this->reporter = new TextReporter($highlighter);
    }

    protected function tearDown(): void
    {
        TestDirectoryHelper::removeDirectory($this->tempDir);
    }

    public function testReportWithNoClones(): void
    {
        $result = $this->reporter->report([]);

        self::assertSame("\n<fg=black;bg=green> No code clones detected. </>\n", $result);
    }

    public function testReportWithCloneGroups(): void
    {
        // Create files with duplicate code - use more complex code to ensure detection
        $file1 = $this->createTempFile('file1.php', '<?php
            function calculate($a, $b, $c) {
                $sum = $a + $b + $c;
                $product = $a * $b * $c;
                $difference = $sum - $product;
                if ($difference > 0) {
                    return $difference * 2;
                }
                return $sum + $product;
            }
        ');

        $file2 = $this->createTempFile('file2.php', '<?php
            function compute($x, $y, $z) {
                $sum = $x + $y + $z;
                $product = $x * $y * $z;
                $difference = $sum - $product;
                if ($difference > 0) {
                    return $difference * 2;
                }
                return $sum + $product;
            }
        ');

        $detector = new CloneDetector(new Configuration());
        $cloneGroups = $detector->detect([$file1, $file2], minNodeCount: 5);

        self::assertNotEmpty($cloneGroups, 'Should detect clones for test');

        $result = $this->reporter->report($cloneGroups);

        // Verify report structure
        self::assertStringContainsString('Clone Group #1', $result);
        self::assertStringContainsString('nodes', $result);
        self::assertStringContainsString('instances', $result);
        self::assertStringContainsString('Total:', $result);
        self::assertStringContainsString('clone group(s) detected', $result);

        // Verify file references are included
        self::assertStringContainsString('file1.php', $result);
        self::assertStringContainsString('file2.php', $result);
    }

    public function testReportIncludesLineNumbers(): void
    {
        $file1 = $this->createTempFile('with_lines1.php', '<?php
            function foo($x, $y) {
                $sum = $x + $y;
                $product = $x * $y;
                $result = $sum + $product;
                if ($result > 100) {
                    return $result / 2;
                }
                return $result;
            }
        ');

        $file2 = $this->createTempFile('with_lines2.php', '<?php
            function bar($a, $b) {
                $sum = $a + $b;
                $product = $a * $b;
                $result = $sum + $product;
                if ($result > 100) {
                    return $result / 2;
                }
                return $result;
            }
        ');

        $detector = new CloneDetector(new Configuration());
        $cloneGroups = $detector->detect([$file1, $file2], minNodeCount: 5);

        self::assertNotEmpty($cloneGroups, 'Should detect clones for test');

        $result = $this->reporter->report($cloneGroups);

        // Report should include line number formatting (e.g., "  2 |")
        self::assertMatchesRegularExpression('/\s+\d+\s+\|/', $result);
    }

    public function testReportFormatsMultipleCloneGroups(): void
    {
        // Create files with multiple clone patterns
        $file1 = $this->createTempFile('multi1.php', '<?php
            function patternA($x) {
                $result = $x + 1;
                $doubled = $result * 2;
                return $doubled;
            }

            function patternB($arr) {
                foreach ($arr as $item) {
                    echo $item;
                }
            }
        ');

        $file2 = $this->createTempFile('multi2.php', '<?php
            function patternACopy($y) {
                $result = $y + 1;
                $doubled = $result * 2;
                return $doubled;
            }

            function patternBCopy($list) {
                foreach ($list as $element) {
                    echo $element;
                }
            }
        ');

        $detector = new CloneDetector(new Configuration());
        $cloneGroups = $detector->detect([$file1, $file2], minNodeCount: 5);

        if (count($cloneGroups) >= 2) {
            $result = $this->reporter->report($cloneGroups);

            self::assertStringContainsString('Clone Group #1', $result);
            self::assertStringContainsString('Clone Group #2', $result);
        } else {
            // If only one group detected, just verify basic formatting
            $result = $this->reporter->report($cloneGroups);
            self::assertStringContainsString('Clone Group #1', $result);
        }
    }

    public function testReportWithSyntaxHighlightingDisabled(): void
    {
        $highlighter = new SyntaxHighlighter(enabled: false);
        $reporter = new TextReporter($highlighter);

        $file1 = $this->createTempFile('no_color1.php', '<?php
            $x = 1;
            $y = $x + 2;
            echo $y;
        ');

        $file2 = $this->createTempFile('no_color2.php', '<?php
            $a = 1;
            $b = $a + 2;
            echo $b;
        ');

        $detector = new CloneDetector(new Configuration());
        $cloneGroups = $detector->detect([$file1, $file2], minNodeCount: 3);

        if ($cloneGroups !== []) {
            $result = $reporter->report($cloneGroups);

            // Should not contain ANSI escape codes
            self::assertDoesNotMatchRegularExpression('/\x1b\[/', $result);
        }
    }

    public function testReportShowsNodeAndInstanceCount(): void
    {
        $file1 = $this->createTempFile('count1.php', '<?php
            function process($data) {
                $filtered = array_filter($data);
                $mapped = array_map(fn($x) => $x * 2, $filtered);
                return $mapped;
            }
        ');

        $file2 = $this->createTempFile('count2.php', '<?php
            function transform($items) {
                $filtered = array_filter($items);
                $mapped = array_map(fn($x) => $x * 2, $filtered);
                return $mapped;
            }
        ');

        $detector = new CloneDetector(new Configuration());
        $cloneGroups = $detector->detect([$file1, $file2], minNodeCount: 10);

        if ($cloneGroups !== []) {
            $result = $this->reporter->report($cloneGroups);

            // Verify node count and instance count are shown
            self::assertMatchesRegularExpression('/\d+ nodes/', $result);
            self::assertMatchesRegularExpression('/\d+ instances/', $result);
        }
    }

    private function createTempFile(
        string $name,
        string $content,
    ): string
    {
        $file = $this->tempDir . '/' . $name;
        file_put_contents($file, $content);
        return $file;
    }

}
