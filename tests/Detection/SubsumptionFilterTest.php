<?php declare(strict_types = 1);

namespace ShipMonk\CopyPasteDetectorTests\Detection;

use PHPUnit\Framework\TestCase;
use ShipMonk\CopyPasteDetector\Config\Config;
use ShipMonk\CopyPasteDetector\Detection\CloneDetector;
use ShipMonk\CopyPasteDetector\Detection\CloneGroup;
use function basename;
use function sort;

final class SubsumptionFilterTest extends TestCase
{

    private CloneDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new CloneDetector(new Config());
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

    public function testSubsumedNestedClonesAreFiltered(): void
    {
        // Two files with identical foreach+if/else structure (different var names only).
        // Without filtering this produces 15 groups (nested subtrees of the foreach).
        // With filtering, only the foreach (lines 4-10) and the init $result=[] (line 3,
        // which is outside the foreach range) survive.
        $cloneGroups = $this->detect([
            __DIR__ . '/../_fixtures/subsumption/nested/Outer1.php',
            __DIR__ . '/../_fixtures/subsumption/nested/Outer2.php',
        ], minNodeCount: 3);

        // The foreach (lines 4-10) is the largest clone and subsumes all inner
        // subtrees (if/else, assignments, etc.). The $result=[] init at line 3
        // is outside that range so it forms its own surviving group.
        self::assertGroupRanges($cloneGroups, [
            ['Outer1.php:4-10', 'Outer2.php:4-10'],
            ['Outer1.php:3-3', 'Outer2.php:3-3'],
        ]);
    }

    public function testPartiallySubsumedGroupIsRetained(): void
    {
        // Process1+Process2: same function name "process" so the function-level clone forms
        // (function names are not anonymized by default).
        // InnerOnly: only the inner foreach+if body, no function wrapper.
        //
        // The outer function clone (Process1:2-10, Process2:2-10) is retained first.
        // The foreach clone has 3 instances (Process1:4-8, Process2:4-8, InnerOnly:3-7) —
        // instances in Process1+Process2 are subsumed by the outer function, but the
        // instance in InnerOnly is NOT covered → group survives (partially subsumed).
        // The $sum=0 init has 3 instances (Process1:3-3, Process2:3-3, InnerOnly:2-2) —
        // Process1+Process2 instances are within the outer function range (2-10), but
        // InnerOnly:2-2 is outside the foreach range (3-7) → group also survives.
        $cloneGroups = $this->detect([
            __DIR__ . '/../_fixtures/subsumption/partial/Process1.php',
            __DIR__ . '/../_fixtures/subsumption/partial/Process2.php',
            __DIR__ . '/../_fixtures/subsumption/partial/InnerOnly.php',
        ], minNodeCount: 3);

        self::assertGroupRanges($cloneGroups, [
            // Outer function clone (2 instances, Process1+Process2 only)
            ['Process1.php:2-10', 'Process2.php:2-10'],
            // Foreach clone (3 instances) — not fully subsumed because InnerOnly is uncovered
            ['InnerOnly.php:3-7', 'Process1.php:4-8', 'Process2.php:4-8'],
            // Init $sum=0 (3 instances) — not fully subsumed because InnerOnly:2-2 is uncovered
            ['InnerOnly.php:2-2', 'Process1.php:3-3', 'Process2.php:3-3'],
        ]);
    }

    public function testNonOverlappingGroupsBothRetained(): void
    {
        // Two structurally different clone patterns in non-overlapping line ranges.
        // PatternA (lines 2-7): arithmetic assignments — function names differ so no
        // function-level clone, but individual statements clone at lines 3-6.
        // PatternB (lines 9-15): for loop — the for loop (lines 11-13) subsumes
        // inner subtrees; the $arr=[] init (line 10) is outside the loop range.
        $cloneGroups = $this->detect([
            __DIR__ . '/../_fixtures/subsumption/nonoverlap/TwoPatterns1.php',
            __DIR__ . '/../_fixtures/subsumption/nonoverlap/TwoPatterns2.php',
        ], minNodeCount: 3);

        // Both pattern regions produce surviving groups since they don't overlap.
        // PatternB: for loop (11-13) subsumes inner subtrees; $arr=[] (10) survives independently.
        // PatternA: individual statements at lines 3-6 each form their own groups
        // (no larger enclosing clone exists because function names differ).
        self::assertGroupRanges($cloneGroups, [
            // For loop from patternB
            ['TwoPatterns1.php:11-13', 'TwoPatterns2.php:11-13'],
            // Individual statements from patternA ($a=$x+1, $b=$a*2, $c=$b-3)
            ['TwoPatterns1.php:3-3', 'TwoPatterns2.php:3-3'],
            ['TwoPatterns1.php:4-4', 'TwoPatterns2.php:4-4'],
            ['TwoPatterns1.php:5-5', 'TwoPatterns2.php:5-5'],
            // Return from patternA + $arr=[] init from patternB
            ['TwoPatterns1.php:6-6', 'TwoPatterns2.php:6-6'],
            ['TwoPatterns1.php:10-10', 'TwoPatterns2.php:10-10'],
        ]);
    }

    /**
     * Assert that clone groups match the expected line ranges exactly.
     *
     * @param list<CloneGroup> $cloneGroups
     * @param list<list<string>> $expectedGroups Each group is a list of "basename:startLine-endLine" strings
     */
    private static function assertGroupRanges(
        array $cloneGroups,
        array $expectedGroups,
    ): void
    {
        $actual = [];

        foreach ($cloneGroups as $group) {
            $instances = [];

            foreach ($group->getSubtrees() as $subtree) {
                $instances[] = basename($subtree->getFilePath()) . ':' . $subtree->getStartLine() . '-' . $subtree->getEndLine();
            }

            sort($instances);
            $actual[] = $instances;
        }

        $expected = [];

        foreach ($expectedGroups as $instanceList) {
            $sorted = $instanceList;
            sort($sorted);
            $expected[] = $sorted;
        }

        self::assertSame($expected, $actual);
    }

}
