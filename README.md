# PHP Copy-Paste Detector

An AST-based structural code clone detector for PHP, inspired by the CloneDR methodology.
This tool efficiently detects Type-2 (parameterized) code clones using AST analysis and hash-based exact matching.


## Installation

```bash
composer require --dev shipmonk/copy-paste-detector
```


## Basic Usage

```bash
vendor/bin/copy-paste-detector src/
```


## CLI Options

- `--config=config.php` or `-c config.php`
  - Path to configuration file
  - Defaults to `copy-paste-detector.php` in current directory
  - Configuration file must return a `CopyPasteDetector\Config\Config` instance


- `--min-node-count=100` or `-m 100`
  - Minimum number of AST nodes for a subtree to be considered
  - Defaults to 50


- `--cache-dir=cache/`
  - Directory for caching parsed structures
  - Defaults to system temp directory


## Configuration File

Create a `copy-paste-detector.php` file in your project root to configure detection settings:

```php
<?php

use CopyPasteDetector\Config\Config;

$config = new Config();

// Set paths to analyze
$config->setPaths(['src/', 'tests/']);

// Set the minimum node count for clone detection
$config->setMinNodeCount(50);

// Set cache directory (optional, defaults to system temp directory)
$config->setCacheDir('cache/copy-paste-detector/');

// Configure anonymization strategies
$config->setAnonymizeVariables(true); // treat variable names like `$foo` and `$bar` as equivalent
$config->setAnonymizeLiterals(false); // treat string and number literals as equivalent
$config->setAnonymizeNames(false); // treat function and class names as equivalent
$config->setAnonymizeIdentifiers(false); // treat method and constant names as equivalent

return $config;
```


## Contributing
- Check your code by `composer check`
- Autofix coding-style by `composer fix:cs`
- All functionality must be tested
