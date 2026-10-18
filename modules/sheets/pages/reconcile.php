<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$newsId = max(1, (int)($_GET['news_id'] ?? $_POST['news_id'] ?? SheetTicketReconcile::configNewsId()));
$fromTs = (int)strtotime('2023-07-01');

$message = '';
$error = '';
$applyResult = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $keys = $_POST['keys'] ?? [];
    if (!is_array($keys)) {
        $keys = [];
    }
    $keys = array_values(array_filter(array_map('strval', $keys)));
    try {
        if ($keys === []) {
            throw new InvalidArgumentException(t('sheets.reconcile.err.none_selected'));
        }
        $applyResult = SheetTicketReconcile::apply($keys, Auth::userId(), $newsId, $fromTs);
        $message = t('sheets.reconcile.applied', [
            'fixed' => (int)$applyResult['fixed'],
            'created' => (int)$applyResult['created'],
            'terms' => (int)$applyResult['terms_balance'],
            'laundry' => (int)$applyResult['laundry_balance'],
        ]);
        if ($applyResult['skipped'] !== []) {
            $error = implode("\n", $applyResult['skipped']);
        }
        if ($applyResult['errors'] !== []) {
            $error = trim($error . "\n" . implode("\n", $applyResult['errors']));
        }
        if ($wantsJson) {
            AgentHttp::emit(['ok' => true, 'result' => $applyResult, 'message' => $message]);
            return;
        }
    } catch (Throwable $e) {
        if ($wantsJson) {
            AgentHttp::error($e->getMessage(), 400);
            return;
        }
        $error = $e->getMessage();
    }
}

$report = SheetTicketReconcile::analyze($newsId, $fromTs);
$accounts = SheetAccountsRepository::names(false);

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'report' => $report,
        'accounts' => $accounts,
        'apply' => $applyResult,
    ]);
    return;
}

View::render('reconcile', [
    'title' => t('sheets.reconcile.title'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('sheets.menu.moves'), 'href' => '/sheets/moves'],
        ['label' => t('sheets.reconcile.menu'), 'current' => true],
    ]),
    'report' => $report,
    'accounts' => $accounts,
    'newsId' => $newsId,
    'message' => $message,
    'error' => $error,
], 'layout', 'sheets');
