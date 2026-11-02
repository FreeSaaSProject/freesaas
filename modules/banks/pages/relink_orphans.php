<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$accountId = (int)($_POST['account_id'] ?? 0);
$provider = BanksConfig::normalizeProvider((string)($_POST['provider'] ?? 'psb_card'));
$daySlack = max(0, min(31, (int)($_POST['day_slack'] ?? 3)));
$fallback = $accountId > 0
    ? ListQuery::url('/ledger/moves', ['account_id' => (string)$accountId], [])
    : '/banks';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    if ($wantsJson) {
        AgentHttp::error(t('banks.import.err.method'), 405);
    }
    flash_set('error', t('banks.import.err.method'));
    redirect(NavContext::backOr($fallback));
}

if ($accountId < 1 || $provider === '' || !BanksConfig::isFileProvider($provider)) {
    if ($wantsJson) {
        AgentHttp::error(t('banks.err.provider'), 400);
    }
    flash_set('error', t('banks.err.provider'));
    redirect(NavContext::backOr($fallback));
}

try {
    $stats = BankSyncService::relinkOrphanMoves($accountId, $provider, $daySlack);
    $msg = t('banks.relink.done', [
        'relinked' => $stats['relinked'],
        'deleted' => $stats['deleted'],
        'left' => $stats['left'],
    ]);
    if ($wantsJson) {
        AgentHttp::emit(['ok' => true, 'stats' => $stats, 'message' => $msg, 'account_id' => $accountId]);
    }
    flash_set($stats['relinked'] > 0 ? 'ok' : 'warn', $msg);
} catch (Throwable $e) {
    if ($wantsJson) {
        AgentHttp::error($e->getMessage(), 400);
    }
    flash_set('error', $e->getMessage());
}

redirect(NavContext::backOr($fallback));
