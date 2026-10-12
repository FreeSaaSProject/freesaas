<?php
declare(strict_types=1);
$dir=__DIR__;
foreach ([
    'DbTables.php',
    'DbFs.php',
    'DbDump.php',
    'DbRestore.php',
    'DbFetch.php',
    'DbMaint.php',
    'DbJobs.php',
] as $f) {
    require_once $dir . '/' . $f;
}
