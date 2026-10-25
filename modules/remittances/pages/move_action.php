<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$moveId = (int)Router::param('moveId');
$action = trim((string)($_POST['action'] ?? ''));
$ret = trim((string)($_POST['ret'] ?? ''));
if ($ret === '' || strpos($ret, '/ledger/moves/') !== 0) {
    $ret = '/ledger/moves/' . $moveId . '/edit';
}

if ($moveId < 1 || $action === '') {
    if ($wantsJson) {
        AgentHttp::error(t('remittances.err.bad_request'), 400);
    }
    flash_set('error', t('remittances.err.bad_request'));
    redirect($ret);
}

if (!Auth::can('ledger.edit') && !Auth::can('remittances.manage')) {
    if ($wantsJson) {
        AgentHttp::error(t('remittances.err.forbidden'), 403);
    }
    flash_set('error', t('remittances.err.forbidden'));
    redirect($ret);
}

try {
    if ($action === 'sync') {
        $batch = RemittanceReconcile::syncMove($moveId, null, true);
        if ($batch === null) {
            throw new RuntimeException(t('remittances.err.no_agreement'));
        }
        $msg = t('remittances.flash.synced', ['status' => (string)$batch['status']]);
    } elseif ($action === 'ignore') {
        RemittanceReconcile::syncMove($moveId);
        RemittanceBatchesRepository::setIgnored($moveId, true);
        $msg = t('remittances.flash.ignored');
    } elseif ($action === 'unignore') {
        RemittanceBatchesRepository::setIgnored($moveId, false);
        RemittanceReconcile::syncMove($moveId, null, true);
        $msg = t('remittances.flash.unignored');
    } elseif ($action === 'post_fee') {
        $res = RemittanceFeePoster::postForMove($moveId);
        if (!$res['ok']) {
            $errKey = 'remittances.err.fee_' . $res['error'];
            $translated = t($errKey);
            throw new RuntimeException($translated !== $errKey ? $translated : t('remittances.err.fee_failed'));
        }
        $msg = t('remittances.flash.fee_posted', ['id' => (string)$res['fee_move_id']]);
    } else {
        throw new InvalidArgumentException(t('remittances.err.bad_action'));
    }
    if ($wantsJson) {
        AgentHttp::emit(['ok' => true, 'action' => $action, 'move_id' => $moveId, 'message' => $msg]);
        return;
    }
    flash_set('ok', $msg);
    redirect($ret);
} catch (Throwable $e) {
    if ($wantsJson) {
        AgentHttp::error($e->getMessage(), 400);
    }
    flash_set('error', $e->getMessage());
    redirect($ret);
}
