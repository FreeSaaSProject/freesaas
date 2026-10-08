<?php
declare(strict_types=1);

BanksJobsAuth::assertToken();

$dateFrom = trim((string)($_POST['date_from'] ?? $_GET['date_from'] ?? date('Y-m-d', strtotime('-2 days'))));
$dateTo = trim((string)($_POST['date_to'] ?? $_GET['date_to'] ?? date('Y-m-d')));

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'bad date_from/date_to'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $stats = BankSyncService::syncAllEnabled($dateFrom, $dateTo);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'stats' => $stats], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
