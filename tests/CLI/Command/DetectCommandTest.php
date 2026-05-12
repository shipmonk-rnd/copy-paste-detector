<?php declare(strict_types = 1);

namespace ShipMonk\CopyPasteDetectorTests\CLI\Command;

use PHPUnit\Framework\TestCase;
use ShipMonk\CopyPasteDetector\CLI\Command\DetectCommand;
use ShipMonk\CopyPasteDetector\Exception\ErrorException;
use ShipMonk\CopyPasteDetectorTests\Helpers\TestDirectoryHelper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use function count;
use function explode;
use function file_put_contents;
use function glob;
use function implode;
use function mkdir;
use function rtrim;
use function sprintf;
use function sys_get_temp_dir;
use function uniqid;
use function var_export;

final class DetectCommandTest extends TestCase
{

    private const FIXTURES = __DIR__ . '/../../_fixtures/sample_code';

    private string $tempDir;
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/cpd-cmd-test-' . uniqid();
        $this->cacheDir = $this->tempDir . '/cache';
        mkdir($this->tempDir, 0755, true);
        mkdir($this->cacheDir, 0755, true);
    }

    protected function tearDown(): void
    {
        TestDirectoryHelper::removeDirectory($this->tempDir);
    }

    public function testDetectsClonesAndReturnsFailure(): void
    {
        $tester = $this->createTester();

        $exitCode = $tester->execute([
            'paths' => [self::FIXTURES],
            '--min-node-count' => '10',
            '--cache-dir' => $this->cacheDir,
        ]);

        self::assertSame(Command::FAILURE, $exitCode);

        $display = $tester->getDisplay();
        self::assertStringContainsString('Scanning:', $display);
        self::assertStringContainsString('Calculator1.php', $display);
        self::assertStringContainsString('Calculator2.php', $display);
    }

    public function testReturnsSuccessWhenNoClonesFound(): void
    {
        $singleFileDir = $this->tempDir . '/single';
        mkdir($singleFileDir);
        file_put_contents($singleFileDir . '/Only.php', '<?php class Only { public function a(): int { return 1; } }');

        $tester = $this->createTester();

        $exitCode = $tester->execute([
            'paths' => [$singleFileDir],
            '--min-node-count' => '10',
            '--cache-dir' => $this->cacheDir,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('No code clones detected', $tester->getDisplay());
    }

    public function testThrowsWhenPathDoesNotExist(): void
    {
        $tester = $this->createTester();

        $this->expectException(ErrorException::class);
        $this->expectExceptionMessage('Path does not exist');

        $tester->execute([
            'paths' => [$this->tempDir . '/missing'],
            '--cache-dir' => $this->cacheDir,
        ]);
    }

    public function testThrowsWhenNoPhpFilesFound(): void
    {
        $emptyDir = $this->tempDir . '/empty';
        mkdir($emptyDir);

        $tester = $this->createTester();

        $this->expectException(ErrorException::class);
        $this->expectExceptionMessage('No PHP files found');

        $tester->execute([
            'paths' => [$emptyDir],
            '--cache-dir' => $this->cacheDir,
        ]);
    }

    public function testThrowsWhenNoPathsProvidedAndNoSrcDirExists(): void
    {
        $tester = $this->createTester();

        $this->expectException(ErrorException::class);
        $this->expectExceptionMessage('No paths specified');

        $tester->execute([
            '--cache-dir' => $this->cacheDir,
        ]);
    }

    public function testHighMinNodeCountSuppressesClones(): void
    {
        $tester = $this->createTester();

        $exitCode = $tester->execute([
            'paths' => [self::FIXTURES],
            '--min-node-count' => '9999',
            '--cache-dir' => $this->cacheDir,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('≥9999 nodes', $tester->getDisplay());
    }

    public function testLoadsExplicitConfigFile(): void
    {
        $configFile = $this->writeConfig('custom-config.php', [
            ['setMinNodeCount', 10],
            ['setCacheDir', $this->cacheDir],
            ['addPath', self::FIXTURES],
        ]);

        $tester = $this->createTester();

        $exitCode = $tester->execute([
            '--config' => $configFile,
        ]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Calculator1.php', $tester->getDisplay());
    }

    public function testAutoDetectsConfigInCwd(): void
    {
        $this->writeConfig('copy-paste-detector.php', [
            ['setMinNodeCount', 10],
            ['setCacheDir', $this->cacheDir],
            ['addPath', self::FIXTURES],
        ]);

        $tester = $this->createTester();

        $tester->execute([]);

        self::assertStringContainsString('Config:', $tester->getDisplay());
        self::assertStringContainsString('copy-paste-detector.php', $tester->getDisplay());
    }

    public function testCliPathsOverrideConfigPaths(): void
    {
        $configuredDir = $this->tempDir . '/configured';
        mkdir($configuredDir);
        file_put_contents($configuredDir . '/Lonely.php', '<?php class Lonely {}');

        $this->writeConfig('copy-paste-detector.php', [
            ['setMinNodeCount', 10],
            ['setCacheDir', $this->cacheDir],
            ['addPath', $configuredDir],
        ]);

        $tester = $this->createTester();
        $tester->execute(['paths' => [self::FIXTURES]]);

        self::assertStringContainsString('config value overridden by cli', $tester->getDisplay());
    }

    public function testRespectsExcludePathsFromConfig(): void
    {
        $configFile = $this->writeConfig('copy-paste-detector.php', [
            ['setMinNodeCount', 10],
            ['setCacheDir', $this->cacheDir],
            ['addExcludePath', self::FIXTURES . '/Calculator2.php'],
        ]);

        $tester = $this->createTester();

        $exitCode = $tester->execute([
            'paths' => [self::FIXTURES],
            '--config' => $configFile,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode, 'Excluding the duplicate file should hide all clones');

        $display = $tester->getDisplay();
        self::assertStringContainsString('Excluding:', $display);
        self::assertStringContainsString('Calculator2.php', $display);
    }

    public function testWarnsAboutExcludePathOutsideScannedPaths(): void
    {
        $outsideDir = $this->tempDir . '/outside';
        mkdir($outsideDir);

        $configFile = $this->writeConfig('copy-paste-detector.php', [
            ['setMinNodeCount', 10],
            ['setCacheDir', $this->cacheDir],
            ['addExcludePath', $outsideDir],
        ]);

        $tester = $this->createTester();
        $tester->execute([
            'paths' => [self::FIXTURES],
            '--config' => $configFile,
        ]);

        self::assertStringContainsString('is not within any scanned path', $tester->getDisplay());
    }

    public function testThrowsWhenExcludePathDoesNotExist(): void
    {
        $configFile = $this->writeConfig('copy-paste-detector.php', [
            ['setMinNodeCount', 10],
            ['setCacheDir', $this->cacheDir],
            ['addExcludePath', 'nonexistent-dir'],
        ]);

        $tester = $this->createTester();

        $this->expectException(ErrorException::class);
        $this->expectExceptionMessage('Exclude path does not exist');

        $tester->execute([
            'paths' => [self::FIXTURES],
            '--config' => $configFile,
        ]);
    }

    public function testCacheDirOverrideIsReportedInOutput(): void
    {
        $configCacheDir = $this->tempDir . '/config-cache';
        mkdir($configCacheDir);

        $configFile = $this->writeConfig('copy-paste-detector.php', [
            ['setMinNodeCount', 10],
            ['setCacheDir', $configCacheDir],
        ]);

        $tester = $this->createTester();
        $tester->execute([
            'paths' => [self::FIXTURES],
            '--cache-dir' => $this->cacheDir,
            '--config' => $configFile,
        ]);

        $display = $tester->getDisplay();
        self::assertStringContainsString('Cache:', $display);
        self::assertStringContainsString('config value overridden by cli', $display);

        // cache files should be written to the cli-supplied dir, not the configured one
        $cliCacheFiles = glob($this->cacheDir . '/*');
        self::assertIsArray($cliCacheFiles);
        self::assertNotEmpty($cliCacheFiles, 'CLI cache dir should contain cache files');
    }

    public function testAcceptsSingleFileAsPath(): void
    {
        $tester = $this->createTester();

        $exitCode = $tester->execute([
            'paths' => [self::FIXTURES . '/Calculator1.php'],
            '--min-node-count' => '5',
            '--cache-dir' => $this->cacheDir,
        ]);

        // a single file may still contain internal clones; we only assert it runs cleanly
        self::assertContains($exitCode, [Command::SUCCESS, Command::FAILURE]);
        self::assertStringContainsString('Calculator1.php', $tester->getDisplay());
    }

    public function testPatchModeReportsOnlyClonesTouchingChangedLines(): void
    {
        $gitRoot = $this->tempDir . '/repo';
        $srcDir = $gitRoot . '/src';
        mkdir($srcDir, 0755, true);
        file_put_contents($gitRoot . '/.git', '');

        $foreachBody = <<<'BODY'
        $result = 0;
        foreach ($numbers as $number) {
            if ($number > 0) {
                $result += $number;
            }
        }
        return $result;
BODY;

        $existing = "<?php\nfunction sumExisting(array \$numbers): int {\n{$foreachBody}\n}\n";
        $newCalc = "<?php\nfunction sumNew(array \$numbers): int {\n{$foreachBody}\n}\n";

        file_put_contents($srcDir . '/Existing.php', $existing);
        file_put_contents($srcDir . '/NewCalc.php', $newCalc);

        // Build a patch that adds NewCalc.php in full (so every line of the file is a changed line).
        $patchLines = [];
        foreach (explode("\n", rtrim($newCalc, "\n")) as $line) {
            $patchLines[] = '+' . $line;
        }
        $hunkCount = count($patchLines);

        $patchFile = $this->tempDir . '/changes.patch';
        file_put_contents($patchFile, <<<PATCH
diff --git a/src/NewCalc.php b/src/NewCalc.php
new file mode 100644
index 0000000..1111111
--- /dev/null
+++ b/src/NewCalc.php
@@ -0,0 +1,{$hunkCount} @@
{$this->joinLines($patchLines)}
PATCH);

        $tester = new CommandTester(new DetectCommand($gitRoot));

        $exitCode = $tester->execute([
            'paths' => [$srcDir],
            '--min-node-count' => '10',
            '--cache-dir' => $this->cacheDir,
            '--patch' => $patchFile,
        ]);

        $display = $tester->getDisplay();

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Patch:', $display);
        self::assertStringContainsString('NewCalc.php', $display);
        self::assertStringContainsString('Existing.php', $display);
        self::assertStringContainsString('new ↔', $display);
    }

    public function testPatchModeReportsIntraMrDuplication(): void
    {
        $gitRoot = $this->tempDir . '/repo';
        $srcDir = $gitRoot . '/src';
        mkdir($srcDir, 0755, true);
        file_put_contents($gitRoot . '/.git', '');

        // Single new file containing two copy-pasted functions — both instances
        // live inside the patch, but the duplication is introduced by the MR.
        $body = <<<'BODY'
<?php
function sumA(array $items): int {
    $result = 0;
    foreach ($items as $item) {
        if ($item > 0) {
            $result += $item;
        }
    }
    return $result;
}

function sumB(array $items): int {
    $result = 0;
    foreach ($items as $item) {
        if ($item > 0) {
            $result += $item;
        }
    }
    return $result;
}
BODY;

        $newFile = $srcDir . '/Dupes.php';
        file_put_contents($newFile, $body);

        $patchLines = [];
        foreach (explode("\n", rtrim($body, "\n")) as $line) {
            $patchLines[] = '+' . $line;
        }
        $hunkCount = count($patchLines);

        $patchFile = $this->tempDir . '/changes.patch';
        file_put_contents($patchFile, <<<PATCH
diff --git a/src/Dupes.php b/src/Dupes.php
new file mode 100644
index 0000000..1111111
--- /dev/null
+++ b/src/Dupes.php
@@ -0,0 +1,{$hunkCount} @@
{$this->joinLines($patchLines)}
PATCH);

        $tester = new CommandTester(new DetectCommand($gitRoot));

        $exitCode = $tester->execute([
            'paths' => [$srcDir],
            '--min-node-count' => '10',
            '--cache-dir' => $this->cacheDir,
            '--patch' => $patchFile,
        ]);

        $display = $tester->getDisplay();

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('intra-MR duplication', $display);
    }

    public function testPatchModeHidesClonesEntirelyInUnchangedCode(): void
    {
        $gitRoot = $this->tempDir . '/repo';
        $srcDir = $gitRoot . '/src';
        mkdir($srcDir, 0755, true);
        file_put_contents($gitRoot . '/.git', '');

        // Two pre-existing files that clone each other, plus an unrelated tiny patch.
        $body = <<<'BODY'
<?php
function process(array $values): int {
    $result = 0;
    foreach ($values as $value) {
        if ($value > 0) {
            $result += $value;
        }
    }
    return $result;
}
BODY;
        file_put_contents($srcDir . '/Alpha.php', $body);
        file_put_contents($srcDir . '/Beta.php', $body);

        $patchFile = $this->tempDir . '/unrelated.patch';
        $unrelatedFile = $srcDir . '/Note.php';
        file_put_contents($unrelatedFile, "<?php\n// just a note\n");
        file_put_contents($patchFile, <<<'PATCH'
diff --git a/src/Note.php b/src/Note.php
new file mode 100644
index 0000000..1111111
--- /dev/null
+++ b/src/Note.php
@@ -0,0 +1,2 @@
+<?php
+// just a note
PATCH);

        $tester = new CommandTester(new DetectCommand($gitRoot));

        $exitCode = $tester->execute([
            'paths' => [$srcDir],
            '--min-node-count' => '10',
            '--cache-dir' => $this->cacheDir,
            '--patch' => $patchFile,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('No code clones detected', $tester->getDisplay());
    }

    /**
     * @param list<string> $lines
     */
    private function joinLines(array $lines): string
    {
        return implode("\n", $lines);
    }

    public function testSkipsNonPhpFiles(): void
    {
        $mixedDir = $this->tempDir . '/mixed';
        mkdir($mixedDir);
        file_put_contents($mixedDir . '/readme.md', 'not php');
        file_put_contents($mixedDir . '/data.txt', 'also not php');

        $tester = $this->createTester();

        $this->expectException(ErrorException::class);
        $this->expectExceptionMessage('No PHP files found');

        $tester->execute([
            'paths' => [$mixedDir],
            '--cache-dir' => $this->cacheDir,
        ]);
    }

    public function testMinSequenceStmtsOptionEnablesAndConfiguresDetection(): void
    {
        $tester = $this->createTester();

        $exitCode = $tester->execute([
            'paths' => [self::FIXTURES],
            '--min-node-count' => '10',
            '--cache-dir' => $this->cacheDir,
            '--min-sequence-stmts' => '4',
        ]);

        self::assertSame(Command::FAILURE, $exitCode);
    }

    public function testMinSequenceStmtsZeroDisablesSequenceDetection(): void
    {
        $tester = $this->createTester();

        $exitCode = $tester->execute([
            'paths' => [self::FIXTURES],
            '--min-node-count' => '10',
            '--cache-dir' => $this->cacheDir,
            '--min-sequence-stmts' => '0',
        ]);

        // Calculator1+Calculator2 still contain whole-subtree clones,
        // so the run still fails — but the sequence-detection path is
        // exercised in the "disable" branch.
        self::assertSame(Command::FAILURE, $exitCode);
    }

    public function testMinSequenceStmtsOneIsRejected(): void
    {
        $tester = $this->createTester();

        $this->expectException(ErrorException::class);
        $this->expectExceptionMessage('--min-sequence-stmts must be 0 (disable) or at least 2');

        $tester->execute([
            'paths' => [self::FIXTURES],
            '--min-node-count' => '10',
            '--cache-dir' => $this->cacheDir,
            '--min-sequence-stmts' => '1',
        ]);
    }

    private function createTester(): CommandTester
    {
        return new CommandTester(new DetectCommand($this->tempDir));
    }

    /**
     * @param list<array{string, scalar}> $calls Method name + scalar argument pairs invoked on Config
     */
    private function writeConfig(
        string $filename,
        array $calls,
    ): string
    {
        $body = '';
        foreach ($calls as [$method, $arg]) {
            $body .= sprintf("    ->%s(%s)\n", $method, var_export($arg, true));
        }

        $path = $this->tempDir . '/' . $filename;
        $php = "<?php\nuse ShipMonk\\CopyPasteDetector\\Config\\Config;\nreturn (new Config())\n" . $body . ';';
        file_put_contents($path, $php);

        return $path;
    }

}
