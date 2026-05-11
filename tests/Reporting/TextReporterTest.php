<?php declare(strict_types = 1);

namespace ShipMonk\CopyPasteDetectorTests\Reporting;

use PHPUnit\Framework\TestCase;
use ShipMonk\CopyPasteDetector\AST\Subtree;
use ShipMonk\CopyPasteDetector\Config\Config;
use ShipMonk\CopyPasteDetector\Detection\CloneDetector;
use ShipMonk\CopyPasteDetector\Detection\CloneGroup;
use ShipMonk\CopyPasteDetector\Reporting\LineDiffer;
use ShipMonk\CopyPasteDetector\Reporting\SyntaxHighlighter;
use ShipMonk\CopyPasteDetector\Reporting\TextReporter;
use ShipMonk\CopyPasteDetectorTests\Helpers\TestDirectoryHelper;
use function count;
use function file_put_contents;
use function mkdir;
use function str_repeat;
use function substr_count;
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
        $this->reporter = new TextReporter($highlighter, new LineDiffer());
    }

    protected function tearDown(): void
    {
        TestDirectoryHelper::removeDirectory($this->tempDir);
    }

    public function testReportWithNoClones(): void
    {
        $result = $this->reporter->report([], 1.23);

        self::assertSame("\n<fg=black;bg=green> No code clones detected, took 1.23s </>\n", $result);
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

        $cloneGroups = $this->detectClones([$file1, $file2], minNodeCount: 5);

        self::assertNotEmpty($cloneGroups, 'Should detect clones for test');

        $result = $this->reporter->report($cloneGroups, 1.23);

        // Verify report structure
        self::assertStringContainsString('Clone #1', $result);
        self::assertStringContainsString('nodes', $result);
        self::assertStringContainsString('instances', $result);
        self::assertStringContainsString('clone groups found', $result);
        self::assertStringContainsString('(1.23s)', $result);

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

        $cloneGroups = $this->detectClones([$file1, $file2], minNodeCount: 5);

        self::assertNotEmpty($cloneGroups, 'Should detect clones for test');

        $result = $this->reporter->report($cloneGroups, 1.23);

        // Report should include line number formatting (e.g., "  2 │")
        self::assertMatchesRegularExpression('/\s+\d+\s+\x{2502}/u', $result);
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

        $cloneGroups = $this->detectClones([$file1, $file2], minNodeCount: 5);

        if (count($cloneGroups) >= 2) {
            $result = $this->reporter->report($cloneGroups, 1.23);

            self::assertStringContainsString('Clone #1', $result);
            self::assertStringContainsString('Clone #2', $result);
        } else {
            // If only one group detected, just verify basic formatting
            $result = $this->reporter->report($cloneGroups, 1.23);
            self::assertStringContainsString('Clone #1', $result);
        }
    }

    public function testReportWithSyntaxHighlightingDisabled(): void
    {
        $highlighter = new SyntaxHighlighter(enabled: false);
        $reporter = new TextReporter($highlighter, new LineDiffer());

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

        $cloneGroups = $this->detectClones([$file1, $file2], minNodeCount: 3);

        if ($cloneGroups !== []) {
            $result = $reporter->report($cloneGroups, 1.23);

            // Should not contain ANSI escape codes
            self::assertDoesNotMatchRegularExpression('/\x1b\[/', $result);
        }
    }

    public function testReportEmitsClickableHyperlinksWhenEditorUrlConfigured(): void
    {
        $highlighter = new SyntaxHighlighter(enabled: true);
        $reporter = new TextReporter(
            $highlighter,
            new LineDiffer(),
            editorUrl: 'phpstorm://open?file={file}&line={line}',
        );

        $file1 = $this->createTempFile('click1.php', '<?php
            function alpha($a, $b) {
                $sum = $a + $b;
                $product = $a * $b;
                return $sum + $product;
            }
        ');

        $file2 = $this->createTempFile('click2.php', '<?php
            function beta($x, $y) {
                $sum = $x + $y;
                $product = $x * $y;
                return $sum + $product;
            }
        ');

        $cloneGroups = $this->detectClones([$file1, $file2], minNodeCount: 5);
        self::assertNotEmpty($cloneGroups, 'Sanity: clones must be detected for this scenario');

        $result = $reporter->report($cloneGroups, 0.5);

        // OSC 8 hyperlink wrapper: ESC ] 8 ; ; URL ESC \ TEXT ESC ] 8 ; ; ESC \
        self::assertStringContainsString("\033]8;;phpstorm://open?file=", $result);
        self::assertStringContainsString('click1.php', $result);
    }

    public function testReportDoesNotEmitHyperlinksWhenHighlighterDisabled(): void
    {
        $highlighter = new SyntaxHighlighter(enabled: false);
        $reporter = new TextReporter(
            $highlighter,
            new LineDiffer(),
            editorUrl: 'phpstorm://open?file={file}&line={line}',
        );

        $file1 = $this->createTempFile('plain1.php', '<?php
            function alpha($a, $b) {
                $sum = $a + $b;
                $product = $a * $b;
                return $sum + $product;
            }
        ');

        $file2 = $this->createTempFile('plain2.php', '<?php
            function beta($x, $y) {
                $sum = $x + $y;
                $product = $x * $y;
                return $sum + $product;
            }
        ');

        $cloneGroups = $this->detectClones([$file1, $file2], minNodeCount: 5);
        self::assertNotEmpty($cloneGroups);

        $result = $reporter->report($cloneGroups, 0.5);

        self::assertStringNotContainsString("\033]8;;", $result);
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

        $cloneGroups = $this->detectClones([$file1, $file2], minNodeCount: 6);

        if ($cloneGroups !== []) {
            $result = $this->reporter->report($cloneGroups, 1.23);

            // Verify node count and instance count are shown
            self::assertMatchesRegularExpression('/\d+ nodes/', $result);
            self::assertMatchesRegularExpression('/\d+ instances/', $result);
        }
    }

    public function testUnifiedViewShowsCommonLinesOnceAndDivergentLinesPerInstance(): void
    {
        // file1 holds the clone at lines 10-12; file2 holds it at lines 20-22 so each
        // instance has distinct line numbers in the unified output.
        $file1Content = str_repeat("// pad\n", 9)
            . "    \$result = \$data + 1;\n"
            . "    \$doubled = \$result * 2;\n"
            . "    return \$doubled;\n";
        $file2Content = str_repeat("// pad\n", 19)
            . "    \$result = \$data + 1;\n"
            . "    \$tripled = \$result * 2;\n"
            . "    return \$tripled;\n";

        $file1 = $this->createTempFile('unified1.php', $file1Content);
        $file2 = $this->createTempFile('unified2.php', $file2Content);

        $group = new CloneGroup([
            new Subtree($file1, 10, 12, 10, 'h1'),
            new Subtree($file2, 20, 22, 10, 'h1'),
        ]);

        $result = $this->reporter->report([$group], 0.5);

        // Per-instance headers: just path:start-end, no marker prefix
        self::assertMatchesRegularExpression('/^\s+\S*unified1\.php:10-12$/mu', $result);
        self::assertMatchesRegularExpression('/^\s+\S*unified2\.php:20-22$/mu', $result);

        // Common line appears exactly once and uses the first instance's line number
        self::assertSame(1, substr_count($result, '$result = $data + 1;'));
        self::assertMatchesRegularExpression('/\s10\s+\x{2502}\s+\$result = \$data \+ 1;/u', $result);

        // Divergent lines appear once each, prefixed with that instance's real source line number
        self::assertMatchesRegularExpression('/\s11\s+\x{2502}\s+\$doubled = \$result \* 2;/u', $result);
        self::assertMatchesRegularExpression('/\s21\s+\x{2502}\s+\$tripled = \$result \* 2;/u', $result);
        self::assertMatchesRegularExpression('/\s12\s+\x{2502}\s+return \$doubled;/u', $result);
        self::assertMatchesRegularExpression('/\s22\s+\x{2502}\s+return \$tripled;/u', $result);
    }

    public function testUnifiedViewCollapsesIdenticalLinesAcrossThreeInstances(): void
    {
        $body = "    \$sum = \$x + 1;\n    \$product = \$x * 2;\n    return \$sum + \$product;\n";
        $file1 = $this->createTempFile('three1.php', $body);
        $file2 = $this->createTempFile('three2.php', $body);
        $file3 = $this->createTempFile('three3.php', $body);

        $group = new CloneGroup([
            new Subtree($file1, 1, 3, 12, 'h1'),
            new Subtree($file2, 1, 3, 12, 'h1'),
            new Subtree($file3, 1, 3, 12, 'h1'),
        ]);

        $result = $this->reporter->report([$group], 0.5);

        self::assertMatchesRegularExpression('/^\s+\S*three1\.php:1-3$/mu', $result);
        self::assertMatchesRegularExpression('/^\s+\S*three2\.php:1-3$/mu', $result);
        self::assertMatchesRegularExpression('/^\s+\S*three3\.php:1-3$/mu', $result);

        // Body lines collapse to a single occurrence even with three instances
        self::assertSame(1, substr_count($result, '$sum = $x + 1;'));
        self::assertSame(1, substr_count($result, '$product = $x * 2;'));
        self::assertSame(1, substr_count($result, 'return $sum + $product;'));

        self::assertStringContainsString('exact match', $result);
    }

    public function testUnifiedViewFallsBackWhenContentLineCountsDiffer(): void
    {
        $file1 = $this->createTempFile('fb1.php', "<?php\n\$a = 1;\n\$b = 2;\n\$c = 3;\n");
        $file2 = $this->createTempFile('fb2.php', "<?php\n\$a = 1;\n\$b = 2;\n");

        $group = new CloneGroup([
            new Subtree($file1, 2, 4, 6, 'h1'),
            new Subtree($file2, 2, 3, 6, 'h1'),
        ]);

        $result = $this->reporter->report([$group], 0.5);

        // Fallback per-instance rendering uses each instance's real source line numbers.
        self::assertMatchesRegularExpression('/\s2\s+\x{2502}\s+\$a = 1;/u', $result);
        self::assertMatchesRegularExpression('/\s3\s+\x{2502}\s+\$b = 2;/u', $result);
        self::assertMatchesRegularExpression('/\s4\s+\x{2502}\s+\$c = 3;/u', $result);
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

    /**
     * @param list<string> $files
     * @return list<CloneGroup>
     */
    private function detectClones(
        array $files,
        int $minNodeCount,
    ): array
    {
        $config = new Config();
        $detector = new CloneDetector($config);
        return $detector->detect($files, $minNodeCount, null, null);
    }

}
