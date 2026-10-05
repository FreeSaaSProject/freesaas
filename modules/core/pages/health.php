<?php
declare(strict_types=1);

/**
 * Public liveness/readiness probe (minimal JSON). Same engine as CLI health.
 * Full check list: GET /health/detail (requires core.modules) or `php bin/freesaas.php health`.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$report = Health::run(null);
$out = [
    'status' => (string)($report['status'] ?? 'fail'),
    'liveness' => (string)($report['liveness'] ?? 'ok'),
    'readiness' => (string)($report['readiness'] ?? 'fail'),
    'summary' => $report['summary'] ?? ['ok' => 0, 'degraded' => 0, 'fail' => 0, 'skipped' => 0],
];
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
return;
