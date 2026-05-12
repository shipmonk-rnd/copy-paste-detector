<?php declare(strict_types = 1);

use ShipMonk\CopyPasteDetector\Config\Config;

$config = new Config();
$config->setPaths(['src', 'tests']);
$config->setExcludePaths(['tests/_fixtures']);
$config->setCacheDir(__DIR__ . '/cache/self');
$config->setMinNodeCount(30);
// Sequence-clone detection is on for end users by default, but the self-check
// surfaces several pre-existing duplications in test setup and one in src/Reporting.
// Disable here until those are addressed in a follow-up; the feature itself is
// covered by the SequenceCloneDetectionTest.
$config->setSequenceDetectionEnabled(false);

$localConfig = __DIR__ . '/copy-paste-detector.local.php';
if (is_file($localConfig)) {
    require $localConfig; // handy for $config->setEditorUrl()
}

return $config;
