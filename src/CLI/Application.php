<?php declare(strict_types = 1);

namespace CopyPasteDetector\CLI;

use CopyPasteDetector\CLI\Command\DetectCommand;
use Symfony\Component\Console\Application as BaseApplication;

/**
 * CLI Application for Copy-Paste Detector
 */
final class Application extends BaseApplication
{

    public function __construct()
    {
        parent::__construct('Copy-Paste Detector', '1.0.0');

        $this->addCommand(new DetectCommand());
        $this->setDefaultCommand('detect', true);
    }

}
