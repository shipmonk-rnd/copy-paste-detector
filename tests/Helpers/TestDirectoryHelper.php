<?php declare(strict_types = 1);

namespace ShipMonk\CopyPasteDetectorTests\Helpers;

use function is_dir;
use function rmdir;
use function scandir;
use function unlink;

final class TestDirectoryHelper
{

    public static function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $entries = scandir($dir);
        if ($entries !== false) {
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $path = $dir . '/' . $entry;
                if (is_dir($path)) {
                    self::removeDirectory($path);
                } else {
                    unlink($path);
                }
            }
        }
        rmdir($dir);
    }

}
