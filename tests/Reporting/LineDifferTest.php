<?php declare(strict_types = 1);

namespace ShipMonk\CopyPasteDetectorTests\Reporting;

use PHPUnit\Framework\TestCase;
use ShipMonk\CopyPasteDetector\Reporting\LineDiffer;

final class LineDifferTest extends TestCase
{

    private LineDiffer $differ;

    protected function setUp(): void
    {
        $this->differ = new LineDiffer();
    }

    public function testIdenticalLinesReturnNoDiffRanges(): void
    {
        $result = $this->differ->computeDiffRanges([
            ['    $sum = $a + $b;', '    return $sum;'],
            ['    $sum = $a + $b;', '    return $sum;'],
        ]);

        self::assertSame([
            [[], []],
            [[], []],
        ], $result);
    }

    public function testLinesDifferingAtStart(): void
    {
        $result = $this->differ->computeDiffRanges([
            ['return $x;'],
            ['echo $x;'],
        ]);

        // 'return' vs 'echo' — common suffix is ' $x;'
        self::assertSame([
            [[[0, 6]]],
            [[[0, 4]]],
        ], $result);
    }

    public function testLinesDifferingAtEnd(): void
    {
        $result = $this->differ->computeDiffRanges([
            ['$x = foo();'],
            ['$x = bar();'],
        ]);

        // common prefix '$x = ', common suffix '();'
        self::assertSame([
            [[[5, 8]]],
            [[[5, 8]]],
        ], $result);
    }

    public function testLinesDifferingInMiddle(): void
    {
        $result = $this->differ->computeDiffRanges([
            ['$result = $a + $b;'],
            ['$result = $x + $y;'],
        ]);

        // prefix '$result = $' (11), suffix ';' (1)
        // middle: 'a + $b' vs 'x + $y'
        // LCS ' + $' (4 chars) splits into two separate diff ranges
        self::assertSame([
            [[[11, 12], [16, 17]]],
            [[[11, 12], [16, 17]]],
        ], $result);
    }

    public function testLinesOfDifferentLengths(): void
    {
        $result = $this->differ->computeDiffRanges([
            ['$x = 1;'],
            ['$x = 1000;'],
        ]);

        // common prefix: '$x = 1' (6 chars)
        // common suffix: ';' (1 char), but can't extend further without overlapping prefix on short string
        // instance 0 (len 7): diffStart=6, diffEnd=7-1=6, not diffStart < diffEnd => no range
        // instance 1 (len 10): diffStart=6, diffEnd=10-1=9 => range [6, 9] => '000'
        self::assertSame([
            [[]],
            [[[6, 9]]],
        ], $result);
    }

    public function testSingleInstanceReturnsEmpty(): void
    {
        $result = $this->differ->computeDiffRanges([
            ['$x = 1;', '$y = 2;'],
        ]);

        self::assertSame([], $result);
    }

    public function testEmptyInputReturnsEmpty(): void
    {
        $result = $this->differ->computeDiffRanges([]);

        self::assertSame([], $result);
    }

    public function testMoreThanTwoInstances(): void
    {
        $result = $this->differ->computeDiffRanges([
            ['$x = foo();'],
            ['$x = bar();'],
            ['$x = baz();'],
        ]);

        // common prefix '$x = ' (5), common suffix '();' (3)
        // middles 'foo', 'bar', 'baz' have no common substring >= 3
        self::assertSame([
            [[[5, 8]]],
            [[[5, 8]]],
            [[[5, 8]]],
        ], $result);
    }

    public function testMultipleLinesWithMixedDiffs(): void
    {
        $result = $this->differ->computeDiffRanges([
            ['$a = 1;', '$sum = $a + $b;'],
            ['$a = 1;', '$sum = $x + $y;'],
        ]);

        // Line 0 is identical => no ranges
        // Line 1: prefix '$sum = $' (8), suffix ';' (1)
        //   middle 'a + $b' vs 'x + $y', LCS ' + $' splits into [8,9] and [13,14]
        self::assertSame([
            [[], [[8, 9], [13, 14]]],
            [[], [[8, 9], [13, 14]]],
        ], $result);
    }

    public function testRepeatedVariableOnSameLine(): void
    {
        $result = $this->differ->computeDiffRanges([
            ['if ($foo->isA() || $foo->isB()) {'],
            ['if ($bar->isA() || $bar->isB()) {'],
        ]);

        // prefix 'if ($' (5), suffix '->isB()) {' (11)
        // middle: 'foo->isA() || $foo' vs 'bar->isA() || $bar'
        // LCS '->isA() || $' (12 chars) splits into two diff ranges for each variable
        self::assertSame([
            [[[5, 8], [20, 23]]],
            [[[5, 8], [20, 23]]],
        ], $result);
    }

    public function testExtraBlankLinesAreSkippedForAlignment(): void
    {
        $result = $this->differ->computeDiffRanges([
            ['$a = foo();', '', '$b = bar();'],
            ['$a = foo();', '$b = bar();'],
        ]);

        // Instance 1 has a blank line between two code lines, instance 2 does not.
        // Blank lines are filtered, so content lines align: both have '$a = foo();' and '$b = bar();'.
        // All content lines are identical => no diff ranges.
        self::assertSame([
            [[], [], []],
            [[], []],
        ], $result);
    }

    public function testExtraBlankLinesWithDiffs(): void
    {
        $result = $this->differ->computeDiffRanges([
            ['$x = foo();', '', '$y = 1;'],
            ['$x = bar();', '$y = 1;'],
        ]);

        // After filtering blank lines:
        //   content line 0: '$x = foo();' vs '$x = bar();' => diff [5,8] for both
        //   content line 1: '$y = 1;' vs '$y = 1;' => identical
        // Mapped back: instance 0 line 0 gets the diff, instance 1 line 0 gets the diff
        self::assertSame([
            [[[5, 8]], [], []],
            [[[5, 8]], []],
        ], $result);
    }

}
