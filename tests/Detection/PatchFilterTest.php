<?php declare(strict_types = 1);

namespace ShipMonk\CopyPasteDetectorTests\Detection;

use PHPUnit\Framework\TestCase;
use ShipMonk\CopyPasteDetector\AST\Subtree;
use ShipMonk\CopyPasteDetector\Detection\ChangedLines;
use ShipMonk\CopyPasteDetector\Detection\CloneGroup;
use ShipMonk\CopyPasteDetector\Detection\PatchFilter;
use function array_fill_keys;
use function range;

final class PatchFilterTest extends TestCase
{

    public function testDropsGroupsWithNoChangedInstance(): void
    {
        $changedLines = $this->makeChangedLines(['/repo/A.php' => [100, 101, 102]]);
        $filter = new PatchFilter($changedLines);

        // Both instances are in unchanged code (different files, neither line range overlaps changes).
        $group = new CloneGroup([
            $this->subtree('/repo/B.php', 1, 5),
            $this->subtree('/repo/C.php', 1, 5),
        ]);

        self::assertSame([], $filter->filter([$group]));
    }

    public function testKeepsGroupsWhereAllInstancesAreInsideChangedLines(): void
    {
        $changedLines = $this->makeChangedLines(['/repo/A.php' => range(1, 50)]);
        $filter = new PatchFilter($changedLines);

        // Both instances entirely inside the patch — surfaced as intra-MR duplication
        // (e.g. two newly added methods that copy-paste each other).
        $group = new CloneGroup([
            $this->subtree('/repo/A.php', 1, 10),
            $this->subtree('/repo/A.php', 11, 20),
        ]);

        self::assertSame([$group], $filter->filter([$group]));
    }

    public function testKeepsGroupsThatSpanChangedAndUnchanged(): void
    {
        $changedLines = $this->makeChangedLines(['/repo/New.php' => range(1, 20)]);
        $filter = new PatchFilter($changedLines);

        $group = new CloneGroup([
            $this->subtree('/repo/New.php', 5, 15), // fully inside changed lines
            $this->subtree('/repo/Existing.php', 30, 40), // unchanged
        ]);

        self::assertSame([$group], $filter->filter([$group]));
    }

    public function testPartialOverlapCountsAsUnchanged(): void
    {
        // A subtree whose range only partially overlaps the changed-line set is treated as
        // "elsewhere" — the duplicated structure existed before the patch, only one of its
        // lines happened to be touched, so it qualifies as the copy source.
        $changedLines = $this->makeChangedLines([
            '/repo/Source.php' => [40], // single line touched
            '/repo/New.php' => range(1, 20),
        ]);
        $filter = new PatchFilter($changedLines);

        $group = new CloneGroup([
            $this->subtree('/repo/New.php', 5, 15), // fully inside changed lines
            $this->subtree('/repo/Source.php', 35, 45), // overlaps but not fully inside
        ]);

        self::assertSame([$group], $filter->filter([$group]));
    }

    public function testKeepsGroupWhenChangedInstanceCoexistsInSameFile(): void
    {
        // Adding a new function in File.php that duplicates an unchanged function in the same file.
        $changedLines = $this->makeChangedLines(['/repo/File.php' => range(50, 70)]);
        $filter = new PatchFilter($changedLines);

        $group = new CloneGroup([
            $this->subtree('/repo/File.php', 10, 20), // unchanged
            $this->subtree('/repo/File.php', 55, 65), // inside changed lines
        ]);

        self::assertSame([$group], $filter->filter([$group]));
    }

    /**
     * @param array<string, list<int>> $byFile
     */
    private function makeChangedLines(array $byFile): ChangedLines
    {
        $set = [];

        foreach ($byFile as $file => $lines) {
            $set[$file] = array_fill_keys($lines, true);
        }

        return new ChangedLines($set);
    }

    private function subtree(
        string $file,
        int $startLine,
        int $endLine,
    ): Subtree
    {
        return new Subtree($file, $startLine, $endLine, 10, 'hash');
    }

}
