<?php declare(strict_types = 1);

namespace ShipMonk\CopyPasteDetector\CLI;

use Composer\InstalledVersions;
use ShipMonk\CopyPasteDetector\CLI\Command\DetectCommand;
use ShipMonk\CopyPasteDetector\Exception\ErrorException;
use Symfony\Component\Console\Application as BaseApplication;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use function method_exists;

/**
 * CLI Application for Copy-Paste Detector
 */
final class Application extends BaseApplication
{

    public function __construct()
    {
        $version = InstalledVersions::getPrettyVersion('shipmonk/copy-paste-detector') ?? 'dev';

        parent::__construct('Copy Paste Detector', $version);

        $addCommand = method_exists($this, 'addCommand') ? 'addCommand' : 'add';
        $this->$addCommand(new DetectCommand());
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
