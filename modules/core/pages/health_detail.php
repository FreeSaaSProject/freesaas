<?php
declare(strict_types=1);

/**
 * Detailed health report (JSON). Requires core.modules permission.
 * Filter: ?module=id
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$module = isset($_GET['module']) ? trim((string)$_GET['module']) : null;
if ($module === '') {
    $module = null;
}
$report = Health::run($module);
echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
return;
