<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

if (!ModuleManager::isEnabled('stickers') || !class_exists('Acts2InRepository')) {
    if ($wantsJson) {
        AgentHttp::error(t('ledger.moves.attach_act.err.module'), 404);
        return;
    }
    http_response_code(404);
    View::render('error', ['title' => t('core.common.not_found'), 'message' => t('ledger.moves.attach_act.err.module')]);
    return;
}

$rawIds = (string)($_GET['ids'] ?? $_GET['id'] ?? $_POST['ids'] ?? '');
$ids = [];
foreach (preg_split('/[,\s]+/', $rawIds) ?: [] as $part) {
    $id = (int)$part;
    if ($id > 0) {
        $ids[$id] = $id;
    }
}
$ids = array_values($ids);
$listUrl = NavContext::sanitize((string)($_GET['ret'] ?? $_POST['ret'] ?? '')) ?: '/ledger/moves';

if ($ids === []) {
    if ($wantsJson) {
        AgentHttp::error(t('ledger.moves.attach_act.err.none'), 400);
        return;
    }
    BulkActions::redirectWithResult($listUrl, ['done' => 0, 'skipped' => [], 'error' => t('ledger.moves.attach_act.err.none')]);
    return;
}

$moves = [];
foreach ($ids as $id) {
    $row = LedgerMovesRepository::find($id);
    if ($row && (int)$row['deleted'] === 0) {
        $moves[] = $row;
    }
}
if ($moves === []) {
    if ($wantsJson) {
        AgentHttp::error(t('ledger.moves.attach_act.err.none'), 400);
        return;
    }
    BulkActions::redirectWithResult($listUrl, ['done' => 0, 'skipped' => [], 'error' => t('ledger.moves.attach_act.err.none')]);
    return;
}

$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $actId = (int)($_POST['act2_in'] ?? 0);
        $result = LedgerMovesRepository::attachAct2In($ids, $actId);
        $result['action'] = 'attach_act';
        if ($result['done'] < 1 && $result['skipped'] === []) {
            $result['error'] = t('ledger.moves.attach_act.err.none');
        }
        if ($wantsJson) {
            if ($result['done'] < 1) {
                AgentHttp::error(
                    (string)($result['error'] ?? t('ledger.moves.attach_act.err.none')),
                    400,
                    ['skipped' => $result['skipped'] ?? []]
                );
                return;
            }
            AgentHttp::emit(['ok' => true] + $result);
            return;
        }
        BulkActions::redirectWithResult($listUrl, $result);
        return;
    } catch (Throwable $e) {
        if ($wantsJson) {
            AgentHttp::error($e->getMessage(), 400);
            return;
        }
        $error = $e->getMessage();
    }
}

$actOptions = Acts2InRepository::optionsForAttach(50);
$accounts = LedgerAccountsRepository::names(false);
$types = AccountMoveTypesRepository::names(false);

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'ids' => $ids,
        'moves' => $moves,
        'act_options' => $actOptions,
    ]);
    return;
}

View::render('attach_act', [
    'title' => t('ledger.moves.attach_act.title'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('ledger.menu.moves'), 'href' => '/ledger/moves'],
        ['label' => t('ledger.moves.attach_act.title'), 'current' => true],
    ]),
    'moves' => $moves,
    'ids' => $ids,
    'actOptions' => $actOptions,
    'accounts' => $accounts,
    'types' => $types,
    'listUrl' => $listUrl,
    'error' => $error,
], 'layout', 'ledger');
