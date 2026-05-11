<?php declare(strict_types = 1);

namespace ShipMonk\CopyPasteDetectorTests\Detection;

use PHPUnit\Framework\TestCase;
use ShipMonk\CopyPasteDetector\Detection\PatchParser;
use ShipMonk\CopyPasteDetector\Exception\ErrorException;
use ShipMonk\CopyPasteDetectorTests\Helpers\TestDirectoryHelper;
use function copy;
use function file_put_contents;
use function mkdir;
use function realpath;
use function sys_get_temp_dir;
use function uniqid;

final class PatchParserTest extends TestCase
{

    private const PATCH_FIXTURES = __DIR__ . '/../_fixtures/patches';

    private string $tempDir;
    private string $gitRoot;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/cpd-patch-test-' . uniqid();
        mkdir($this->tempDir, 0755, true);
        $this->gitRoot = $this->tempDir . '/repo';
        mkdir($this->gitRoot);
        file_put_contents($this->gitRoot . '/.git', '');
    }

    protected function tearDown(): void
    {
        TestDirectoryHelper::removeDirectory($this->tempDir);
    }

    public function testParsesAddedLinesIntoChangedSet(): void
    {
        $file = $this->gitRoot . '/src/Sample.php';
        mkdir($this->gitRoot . '/src');
        file_put_contents($file, "<?php\nline2\nline3\nline4\n");

        $patchPath = $this->tempDir . '/changes.patch';
        copy(self::PATCH_FIXTURES . '/add-lines.patch', $patchPath);

        $parser = new PatchParser($this->gitRoot);
        $changed = $parser->parse($patchPath);

        $realFile = (string) realpath($file);
        self::assertTrue($changed->containsRange($realFile, 2, 4));
        self::assertFalse($changed->containsRange($realFile, 1, 1));
        self::assertFalse($changed->containsRange($realFile, 1, 4));
    }

    public function testIgnoresDeletedFiles(): void
    {
        $patchPath = $this->tempDir . '/del.patch';
        copy(self::PATCH_FIXTURES . '/deleted-file.patch', $patchPath);

        $parser = new PatchParser($this->gitRoot);
        $changed = $parser->parse($patchPath);

        self::assertFalse($changed->containsRange($this->gitRoot . '/src/Gone.php', 1, 2));
    }

    public function testRejectsUnknownExtension(): void
    {
        $bogus = $this->tempDir . '/diff.txt';
        file_put_contents($bogus, '');

        $this->expectException(ErrorException::class);
        $this->expectExceptionMessage('expecting .patch or .diff extension');

        (new PatchParser($this->gitRoot))->parse($bogus);
    }

    public function testRejectsMissingFile(): void
    {
        $this->expectException(ErrorException::class);
        $this->expectExceptionMessage('Patch file not found');

        (new PatchParser($this->gitRoot))->parse($this->tempDir . '/nope.patch');
    }

    public function testRejectsNonStandardPrefix(): void
    {
        $patchPath = $this->tempDir . '/weird.patch';
        copy(self::PATCH_FIXTURES . '/non-standard-prefix.patch', $patchPath);

        $this->expectException(ErrorException::class);
        $this->expectExceptionMessage('unsupported prefix');

        (new PatchParser($this->gitRoot))->parse($patchPath);
    }

    public function testThrowsWhenPatchedFileDoesNotExist(): void
    {
        $patchPath = $this->tempDir . '/ghost.patch';
        copy(self::PATCH_FIXTURES . '/added-file-missing.patch', $patchPath);

        $this->expectException(ErrorException::class);
        $this->expectExceptionMessage('does not exist on disk');

        (new PatchParser($this->gitRoot))->parse($patchPath);
    }

    public function testThrowsWhenAddedLineContentDoesNotMatch(): void
    {
        $file = $this->gitRoot . '/src/Sample.php';
        mkdir($this->gitRoot . '/src');
        // Patch expects "expected content" on line 2; we write "actual content" instead.
        file_put_contents($file, "<?php\nactual content\n");

        $patchPath = $this->tempDir . '/mismatch.patch';
        copy(self::PATCH_FIXTURES . '/added-line-mismatch.patch', $patchPath);

        $this->expectException(ErrorException::class);
        $this->expectExceptionMessage('does not match actual content');

        (new PatchParser($this->gitRoot))->parse($patchPath);
    }

    public function testThrowsWhenAddedLineIsBeyondEndOfFile(): void
    {
        $file = $this->gitRoot . '/src/Sample.php';
        mkdir($this->gitRoot . '/src');
        // Patch adds lines 2, 3, 4; on-disk file ends after line 1 (no trailing newline),
        // so line 2 is past EOF and triggers the missing-line error.
        file_put_contents($file, '<?php');

        $patchPath = $this->tempDir . '/beyond-eof.patch';
        copy(self::PATCH_FIXTURES . '/added-line-beyond-eof.patch', $patchPath);

        $this->expectException(ErrorException::class);
        $this->expectExceptionMessage('such line does not exist');

        (new PatchParser($this->gitRoot))->parse($patchPath);
    }

    public function testThrowsWhenNotInGitRepository(): void
    {
        $patch = $this->tempDir . '/x.patch';
        file_put_contents($patch, '');

        $nonGitDir = $this->tempDir . '/not-git';
        mkdir($nonGitDir);

        $this->expectException(ErrorException::class);
        $this->expectExceptionMessage('git repository');

        (new PatchParser($nonGitDir))->parse($patch);
    }

}
