<?php declare(strict_types = 1);

use CopyPasteDetector\Config\Config;

$config = new Config();
$config->setPaths(['src', 'tests']);
$config->setExcludePaths(['tests/_fixtures']);
$config->setCacheDir(__DIR__ . '/cache/self');
$config->setMinNodeCount(30);

return $config;
