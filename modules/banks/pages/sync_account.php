<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$accountId = (int)($_POST['account_id'] ?? $_GET['account_id'] ?? 0);
$dateFrom = trim((string)($_POST['date_from'] ?? $_GET['date_from'] ?? date('Y-m-d', strtotime('-7 days'))));
$dateTo = trim((string)($_POST['date_to'] ?? $_GET['date_to'] ?? date('Y-m-d')));
$fallback = $accountId > 0
    ? ListQuery::url('/ledger/moves', ['account_id' => (string)$accountId], [])
    : '/ledger/moves';

if ($accountId < 1
    || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)
    || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    if ($wantsJson) {
        AgentHttp::error(t('banks.err.sync'), 400);
    }
    flash_set('error', t('banks.err.sync'));
    redirect(NavContext::backOr($fallback));
}

if (BankAccountLinksRepository::forLedgerAccount($accountId) === []) {
    if ($wantsJson) {
        AgentHttp::error(t('banks.sync.nothing_to_sync'), 400);
    }
    flash_set('error', t('banks.sync.nothing_to_sync'));
    redirect(NavContext::backOr($fallback));
}

try {
    $stats = BankSyncService::syncLedgerAccount($accountId, $dateFrom, $dateTo);
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
        AgentHttp::emit(['ok' => true, 'stats' => $stats, 'message' => $msg, 'account_id' => $accountId]);
    }
    flash_set($stats['errors'] > 0 ? 'warn' : 'ok', $msg);
} catch (Throwable $e) {
    if ($wantsJson) {
        AgentHttp::error($e->getMessage(), 400);
    }
    flash_set('error', $e->getMessage());
}

redirect(NavContext::backOr($fallback));
