<?php
declare(strict_types=1);
$dir=__DIR__;
foreach ([
    'UpdateFs.php',
    'UpdateConfig.php',
    'UpdateVersions.php',
    'UpdateGithub.php',
    'UpdateOverlay.php',
    'UpdateRollback.php',
    'UpdateCatalog.php',
    'UpdateCheck.php',
    'UpdateApply.php',
] as $f) {
    require_once $dir . '/' . $f;
}
