<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$accountId = (int)($_POST['account_id'] ?? 0);
$fallback = $accountId > 0
    ? ListQuery::url('/ledger/moves', ['account_id' => (string)$accountId], [])
    : '/ledger/moves';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    if ($wantsJson) {
        AgentHttp::error(t('banks.import.err.method'), 405);
    }
    flash_set('error', t('banks.import.err.method'));
    redirect(NavContext::backOr($fallback));
}

if ($accountId < 1) {
    if ($wantsJson) {
        AgentHttp::error(t('banks.err.ledger_account'), 400);
    }
    flash_set('error', t('banks.err.ledger_account'));
    redirect(NavContext::backOr($fallback));
}

$file = $_FILES['statement'] ?? null;
if (!is_array($file) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    if ($wantsJson) {
        AgentHttp::error(t('banks.import.err.upload'), 400);
    }
    flash_set('error', t('banks.import.err.upload'));
    redirect(NavContext::backOr($fallback));
}

$tmp = (string)($file['tmp_name'] ?? '');
$size = (int)($file['size'] ?? 0);
$name = (string)($file['name'] ?? '');
if ($tmp === '' || !is_uploaded_file($tmp) || $size < 1) {
    if ($wantsJson) {
        AgentHttp::error(t('banks.import.err.upload'), 400);
    }
    flash_set('error', t('banks.import.err.upload'));
    redirect(NavContext::backOr($fallback));
}
if ($size > 5 * 1024 * 1024) {
    if ($wantsJson) {
        AgentHttp::error(t('banks.import.err.size'), 400);
    }
    flash_set('error', t('banks.import.err.size'));
    redirect(NavContext::backOr($fallback));
}
$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
if ($ext !== '' && $ext !== 'csv' && $ext !== 'txt') {
    if ($wantsJson) {
        AgentHttp::error(t('banks.import.err.ext'), 400);
    }
    flash_set('error', t('banks.import.err.ext'));
    redirect(NavContext::backOr($fallback));
}

$csv = (string)file_get_contents($tmp);
if ($csv === '') {
    if ($wantsJson) {
        AgentHttp::error(t('banks.import.err.empty'), 400);
    }
    flash_set('error', t('banks.import.err.empty'));
    redirect(NavContext::backOr($fallback));
}

try {
    $stats = BankSyncService::importPsbCardFile($accountId, $csv);
    $msg = t('banks.import.done', [
        'created' => $stats['created'],
        'linked' => $stats['linked'],
        'skipped' => $stats['skipped'],
        'skipped_purpose' => (int)($stats['skipped_purpose'] ?? 0),
        'errors' => $stats['errors'],
        'n' => $stats['tx_count'],
        'new' => (int)($stats['tx_new'] ?? $stats['tx_count']),
    ]);
    // Keep flash short — detail lines stay in JSON / agent response only.
    if ($wantsJson && !empty($stats['messages'])) {
        $msg .= "\n" . implode("\n", array_slice($stats['messages'], 0, 10));
        if (count($stats['messages']) > 10) {
            $msg .= "\n…";
        }
    }
    if ($wantsJson) {
        AgentHttp::emit([
            'ok' => true,
            'stats' => $stats,
            'message' => $msg,
            'account_id' => $accountId,
            'provider' => 'psb_card',
        ]);
    }
    flash_set($stats['errors'] > 0 ? 'warn' : 'ok', $msg);
} catch (Throwable $e) {
    if ($wantsJson) {
        AgentHttp::error($e->getMessage(), 400);
    }
    flash_set('error', $e->getMessage());
}

redirect(NavContext::backOr($fallback));
