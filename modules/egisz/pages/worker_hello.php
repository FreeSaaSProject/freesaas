<?php
declare(strict_types=1);

EgiszWorkerAuth::assertToken();

$raw = file_get_contents('php://input') ?: '';
$data = json_decode($raw, true);
if (!is_array($data)) {
    $data = $_POST;
}

$workerKey = trim((string)($data['worker_key'] ?? $data['worker'] ?? 'default'));
$hostname = trim((string)($data['hostname'] ?? gethostname() ?: ''));

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'ok' => true,
    'server_time' => time(),
    'worker_enabled' => EgiszWorkerDispatch::enabled(),
    'n3_configured' => EgiszConfig::n3Configured(),
    'n3_env' => EgiszConfig::n3Env(),
    'n3_id_lpu' => EgiszConfig::n3IdLpu(),
    'n3_pix_url' => EgiszConfig::n3PixUrl(),
    'n3_emk_url' => EgiszConfig::n3EmkUrl(),
    'n3_eventlog_url' => EgiszConfig::n3EventlogUrl(),
    'worker_key' => $workerKey,
    'hostname' => $hostname,
], JSON_UNESCAPED_UNICODE);
