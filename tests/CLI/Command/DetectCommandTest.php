<?php declare(strict_types = 1);

namespace ShipMonk\CopyPasteDetectorTests\CLI\Command;

use PHPUnit\Framework\TestCase;
use ShipMonk\CopyPasteDetector\CLI\Command\DetectCommand;
use ShipMonk\CopyPasteDetector\Exception\ErrorException;
use ShipMonk\CopyPasteDetectorTests\Helpers\TestDirectoryHelper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use function file_put_contents;
use function glob;
use function mkdir;
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
