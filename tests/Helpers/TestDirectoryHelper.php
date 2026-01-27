<?php declare(strict_types = 1);

namespace CopyPasteDetector\Tests\Helpers;

use function glob;
use function is_dir;
use function rmdir;
use function unlink;

final class TestDirectoryHelper
{

    public static function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = glob($dir . '/*');
        if ($files !== false) {
            foreach ($files as $file) {
                if (is_dir($file)) {
                    self::removeDirectory($file);
                } else {
                    unlink($file);
                }
            }
        }
        rmdir($dir);
    }

}
