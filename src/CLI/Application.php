<?php declare(strict_types = 1);

namespace CopyPasteDetector\CLI;

use CopyPasteDetector\CLI\Command\DetectCommand;
use CopyPasteDetector\Exception\ErrorException;
use Symfony\Component\Console\Application as BaseApplication;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

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

    public function renderThrowable(
        Throwable $e,
        OutputInterface $output,
    ): void
    {
        if ($e instanceof ErrorException) {
            $output->writeln("<error>Error: {$e->getMessage()}</error>");
            return;
        }

        parent::renderThrowable($e, $output);
    }

}
