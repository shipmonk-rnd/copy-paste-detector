<?php declare(strict_types = 1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType as Error;

return (new Configuration())
    ->ignoreErrorsOnExtension('ext-tokenizer', [Error::SHADOW_DEPENDENCY]); // optional dependency
