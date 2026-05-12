<?php declare(strict_types = 1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType as Error;

return (new Configuration())
    ->ignoreErrorsOnExtension('ext-tokenizer', [Error::SHADOW_DEPENDENCY]) // optional dependency
    ->ignoreErrorsOnPackage('sebastian/diff', [Error::DEV_DEPENDENCY_IN_PROD]); // optional dependency to parse patch files
