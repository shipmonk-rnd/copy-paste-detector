<?php declare(strict_types = 1);

namespace ShipMonk\CopyPasteDetectorTests\Config;

use PHPUnit\Framework\TestCase;
use ShipMonk\CopyPasteDetector\Config\ConfigResolver;
use ShipMonk\CopyPasteDetector\Exception\ErrorException;
use ShipMonk\CopyPasteDetectorTests\Helpers\TestDirectoryHelper;
use function file_put_contents;
use function mkdir;
use function sys_get_temp_dir;
use function uniqid;

final class ConfigResolverTest extends TestCase
{

    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/cpd-config-test-' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        TestDirectoryHelper::removeDirectory($this->tempDir);
    }

    public function testResolveConfigReturnsDefaultWhenNoConfigFileExists(): void
    {
        $resolver = new ConfigResolver($this->tempDir);

        $resolved = $resolver->resolveConfig(null);
        $config = $resolved->getConfig();

        self::assertNull($config->getMinNodeCount());
        self::assertSame([], $config->getPaths());
        self::assertNull($resolved->getUsedConfigPath());
        self::assertTrue($resolved->wasAutoDetected());
    }

    public function testResolveConfigLoadsExplicitConfigFile(): void
    {
        $configFile = $this->tempDir . '/custom-config.php';
        file_put_contents($configFile, '<?php
            use ShipMonk\CopyPasteDetector\Config\Config;
            return (new Config())
                ->setMinNodeCount(50)
                ->addPath("src")
                ->setAnonymizeLiterals(true);
        ');

        $resolver = new ConfigResolver($this->tempDir);

        $resolved = $resolver->resolveConfig($configFile);
        $config = $resolved->getConfig();

        self::assertSame(50, $config->getMinNodeCount());
        self::assertSame(['src'], $config->getPaths());
        self::assertTrue($config->shouldAnonymizeLiterals());
        self::assertSame($configFile, $resolved->getUsedConfigPath());
        self::assertFalse($resolved->wasAutoDetected());
    }

    public function testResolveConfigLoadsDefaultConfigFileFromCwd(): void
    {
        $configFile = $this->tempDir . '/copy-paste-detector.php';
        file_put_contents($configFile, '<?php
            use ShipMonk\CopyPasteDetector\Config\Config;
            return (new Config())
                ->setMinNodeCount(100)
                ->setCacheDir("/tmp/custom-cache");
        ');

        $resolver = new ConfigResolver($this->tempDir);

        $resolved = $resolver->resolveConfig(null);
        $config = $resolved->getConfig();

        self::assertSame(100, $config->getMinNodeCount());
        self::assertSame('/tmp/custom-cache', $config->getCacheDir());
        self::assertSame($configFile, $resolved->getUsedConfigPath());
        self::assertTrue($resolved->wasAutoDetected());
    }

    public function testResolveConfigThrowsForNonExistentExplicitConfigFile(): void
    {
        $resolver = new ConfigResolver($this->tempDir);

        $this->expectException(ErrorException::class);
        $this->expectExceptionMessage('Provided config file not found');

        $resolver->resolveConfig('/nonexistent/config.php');
    }

    public function testResolveConfigThrowsForNonPhpConfigFile(): void
    {
        $configFile = $this->tempDir . '/config.json';
        file_put_contents($configFile, '{}');

        $resolver = new ConfigResolver($this->tempDir);

        $this->expectException(ErrorException::class);
        $this->expectExceptionMessage('must have php extension');

        $resolver->resolveConfig($configFile);
    }

    public function testResolveConfigThrowsWhenConfigDoesNotReturnConfigInstance(): void
    {
        $configFile = $this->tempDir . '/bad-config.php';
        file_put_contents($configFile, '<?php return ["invalid" => true];');

        $resolver = new ConfigResolver($this->tempDir);

        $this->expectException(ErrorException::class);
        $this->expectExceptionMessage('must return an instance of');

        $resolver->resolveConfig($configFile);
    }

    public function testResolveConfigThrowsWhenConfigHasSyntaxError(): void
    {
        $configFile = $this->tempDir . '/syntax-error.php';
        file_put_contents($configFile, '<?php return new Config('); // Missing closing

        $resolver = new ConfigResolver($this->tempDir);

        $this->expectException(ErrorException::class);
        $this->expectExceptionMessage('while loading config file');

        $resolver->resolveConfig($configFile);
    }

    public function testResolveConfigThrowsWhenConfigThrowsException(): void
    {
        $configFile = $this->tempDir . '/throws.php';
        file_put_contents($configFile, '<?php throw new RuntimeException("Config error");');

        $resolver = new ConfigResolver($this->tempDir);

        $this->expectException(ErrorException::class);
        $this->expectExceptionMessage('RuntimeException while loading config file');

        $resolver->resolveConfig($configFile);
    }

}
