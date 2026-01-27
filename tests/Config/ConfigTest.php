<?php declare(strict_types = 1);

namespace ShipMonk\CopyPasteDetectorTests\Config;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ShipMonk\CopyPasteDetector\Config\Config;

final class ConfigTest extends TestCase
{

    public function testDefaultValues(): void
    {
        $config = new Config();

        self::assertNull($config->getMinNodeCount());
        self::assertNull($config->getCacheDir());
        self::assertSame([], $config->getPaths());
        self::assertTrue($config->shouldAnonymizeVariables());
        self::assertFalse($config->shouldAnonymizeLiterals());
        self::assertFalse($config->shouldAnonymizeNames());
        self::assertFalse($config->shouldAnonymizeIdentifiers());
    }

    public function testSetMinNodeCount(): void
    {
        $config = (new Config())->setMinNodeCount(50);

        self::assertSame(50, $config->getMinNodeCount());
    }

    public function testSetMinNodeCountThrowsForZero(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('minNodeCount must be at least 1');

        (new Config())->setMinNodeCount(0);
    }

    public function testSetMinNodeCountThrowsForNegative(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('minNodeCount must be at least 1');

        (new Config())->setMinNodeCount(-5);
    }

    public function testSetCacheDir(): void
    {
        $config = (new Config())->setCacheDir('/custom/cache');

        self::assertSame('/custom/cache', $config->getCacheDir());
    }

    public function testSetPaths(): void
    {
        $paths = ['src', 'lib', 'app'];
        $config = (new Config())->setPaths($paths);

        self::assertSame($paths, $config->getPaths());
    }

    public function testAddPath(): void
    {
        $config = (new Config())
            ->addPath('src')
            ->addPath('lib');

        self::assertSame(['src', 'lib'], $config->getPaths());
    }

    public function testSetAnonymizeVariables(): void
    {
        $config = (new Config())->setAnonymizeVariables(false);

        self::assertFalse($config->shouldAnonymizeVariables());
    }

    public function testSetAnonymizeLiterals(): void
    {
        $config = (new Config())->setAnonymizeLiterals(true);

        self::assertTrue($config->shouldAnonymizeLiterals());
    }

    public function testSetAnonymizeNames(): void
    {
        $config = (new Config())->setAnonymizeNames(true);

        self::assertTrue($config->shouldAnonymizeNames());
    }

    public function testSetAnonymizeIdentifiers(): void
    {
        $config = (new Config())->setAnonymizeIdentifiers(true);

        self::assertTrue($config->shouldAnonymizeIdentifiers());
    }

    public function testFluentInterface(): void
    {
        $config = (new Config())
            ->setMinNodeCount(25)
            ->setCacheDir('/cache')
            ->setPaths(['src'])
            ->addPath('lib')
            ->setAnonymizeVariables(true)
            ->setAnonymizeLiterals(true)
            ->setAnonymizeNames(true)
            ->setAnonymizeIdentifiers(true);

        self::assertSame(25, $config->getMinNodeCount());
        self::assertSame('/cache', $config->getCacheDir());
        self::assertSame(['src', 'lib'], $config->getPaths());
        self::assertTrue($config->shouldAnonymizeVariables());
        self::assertTrue($config->shouldAnonymizeLiterals());
        self::assertTrue($config->shouldAnonymizeNames());
        self::assertTrue($config->shouldAnonymizeIdentifiers());
    }

}
