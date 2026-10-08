<?php
declare(strict_types=1);

$moveId = (int)Router::param('moveId');
if ($moveId < 1 || !ModuleManager::isEnabled('ledger') || !class_exists('LedgerMovesRepository', false)) {
    flash_set('error', t('payments.alloc.err.move'));
    redirect('/payments');
}
$move = LedgerMovesRepository::find($moveId);
if (!$move || (int)$move['deleted'] > 0) {
    flash_set('error', t('payments.alloc.err.move'));
    redirect('/payments');
}
if (!Auth::can('payments.edit') || !Auth::can('ledger.edit')) {
    flash_set('error', t('payments.err.forbidden'));
    redirect('/ledger/moves/' . $moveId . '/edit');
}
if (!PaymentMoveLinksRepository::ready()) {
    flash_set('error', t('payments.alloc.err.bad'));
    redirect('/ledger/moves/' . $moveId . '/edit');
}

$action = (string)($_POST['action'] ?? '');
try {
    if ($action === 'unlink') {
        $pid = (int)($_POST['payment_id'] ?? 0);
        PaymentMoveLinksRepository::unlink($pid, $moveId);
        flash_set('ok', t('payments.alloc.unlinked'));
    } elseif ($action === 'link') {
        $ids = $_POST['payment_ids'] ?? [];
        if (!is_array($ids)) {
            $ids = [];
        }
        $res = PaymentMoveLinksRepository::linkMany($moveId, $ids);
        $msg = t('payments.alloc.linked_n', ['n' => $res['linked']]);
        if ($res['skipped'] !== []) {
            $msg .= "\n" . implode("\n", array_slice($res['skipped'], 0, 8));
            flash_set('warn', $msg);
        } else {
            flash_set('ok', $msg);
        }
    } else {
        flash_set('error', t('payments.alloc.err.bad'));
    }
} catch (Throwable $e) {
    flash_set('error', $e->getMessage());
}

redirect('/ledger/moves/' . $moveId . '/edit');
