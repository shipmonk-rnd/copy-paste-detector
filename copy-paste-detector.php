<?php declare(strict_types = 1);

use ShipMonk\CopyPasteDetector\Config\Config;

$config = new Config();
$config->setPaths(['src', 'tests']);
$config->setExcludePaths(['tests/_fixtures']);
$config->setCacheDir(__DIR__ . '/cache/self');
$config->setMinNodeCount(30);

$localConfig = __DIR__ . '/copy-paste-detector.local.php';
if (is_file($localConfig)) {
    require $localConfig; // handy for $config->setEditorUrl()
}

return $config;
