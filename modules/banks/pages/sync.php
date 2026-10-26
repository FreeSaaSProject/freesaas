<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$id = (int)Router::param('id');
$conn = BankConnectionsRepository::find($id);
if (!$conn) {
    if ($wantsJson) {
        AgentHttp::error(t('banks.err.not_found'), 404);
    }
    flash_set('error', t('banks.err.not_found'));
    redirect('/banks');
}

$dateFrom = trim((string)($_POST['date_from'] ?? $_GET['date_from'] ?? date('Y-m-d', strtotime('-7 days'))));
$dateTo = trim((string)($_POST['date_to'] ?? $_GET['date_to'] ?? date('Y-m-d')));
$linkId = (int)($_POST['link_id'] ?? $_GET['link_id'] ?? 0);

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    if ($wantsJson) {
        AgentHttp::error(t('banks.err.sync'), 400);
    }
    flash_set('error', t('banks.err.sync'));
    redirect('/banks/connections/' . $id);
}

try {
    $stats = BankSyncService::syncConnection(
        $id,
        $dateFrom,
        $dateTo,
        $linkId > 0 ? $linkId : null
    );
    $msg = t('banks.conn.synced', [
        'created' => $stats['created'],
        'linked' => $stats['linked'],
        'skipped' => $stats['skipped'],
        'errors' => $stats['errors'],
    ]);
    if (!empty($stats['messages'])) {
        $msg .= "\n" . implode("\n", array_slice($stats['messages'], 0, 10));
        if (count($stats['messages']) > 10) {
            $msg .= "\n…";
        }
    }
    if ($wantsJson) {
        AgentHttp::emit(['ok' => true, 'stats' => $stats, 'message' => $msg]);
    }
    flash_set($stats['errors'] > 0 ? 'warn' : 'ok', $msg);
} catch (Throwable $e) {
    if ($wantsJson) {
        AgentHttp::error($e->getMessage(), 400);
    }
    flash_set('error', $e->getMessage());
}

redirect(NavContext::backOr('/banks/connections/' . $id));
