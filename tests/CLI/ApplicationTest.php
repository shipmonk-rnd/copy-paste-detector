<?php declare(strict_types = 1);

namespace ShipMonk\CopyPasteDetectorTests\CLI;

use PHPUnit\Framework\TestCase;
use ShipMonk\CopyPasteDetector\CLI\Application;

final class ApplicationTest extends TestCase
{

    public function testRegistersDetectCommandAsDefault(): void
    {
        $application = new Application(__DIR__);

        self::assertSame('Copy Paste Detector', $application->getName());
        self::assertTrue($application->has('detect'));
        self::assertSame('detect', $application->find('detect')->getName());
    }

}
