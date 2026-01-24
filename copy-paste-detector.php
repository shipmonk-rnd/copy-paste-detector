<?php declare(strict_types = 1);

use CopyPasteDetector\Config\Config;

$config = new Config();

// Set paths to analyze
$config->setPaths(['src', 'tests']);

// Set cache directory
$config->setCacheDir(__DIR__ . '/cache/self');

// Set the minimum node count for clone detection
// Higher values = fewer but larger clones detected
$config->setMinNodeCount(30);

// Enable or disable different anonymization strategies:

// Variables: When true, $foo and $bar are considered equivalent (default: true)
$config->setAnonymizeVariables(true);

// Literals: When true, "hello" and "world" or 1 and 2 are considered equivalent (default: false)
$config->setAnonymizeLiterals(false);

// Names: When true, function/class names are considered equivalent (default: false)
$config->setAnonymizeNames(false);

// Identifiers: When true, method/constant names are considered equivalent (default: false)
$config->setAnonymizeIdentifiers(false);

return $config;
