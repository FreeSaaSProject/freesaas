<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$q = trim((string)($_GET['q'] ?? ''));
$accountId = (int)($_GET['account_id'] ?? 0);
$typeId = (int)($_GET['type_id'] ?? 0);
$year = (int)($_GET['year'] ?? 0);
$month = (int)($_GET['month'] ?? 0);
$paymentId = (int)($_GET['payment_id'] ?? ($_GET['payment'] ?? 0));
$supplierId = (int)($_GET['supplier_id'] ?? 0);
$promise = (int)($_GET['promise'] ?? 0);
if (!in_array($promise, [0, 1, 2, 3], true)) {
    $promise = 0;
}
$bankReview = (int)($_GET['bank_review'] ?? 0);
if ($bankReview !== 1) {
    $bankReview = 0;
}
$banksModuleOn = ModuleManager::isEnabled('banks') && class_exists('BanksConfig', false);
$banksFilterAvailable = $banksModuleOn && BanksConfig::defaultMoveTypeId() > 0;
$bankReviewCount = 0;
if ($banksFilterAvailable && class_exists('BankTxMapRepository', false)) {
    $bankReviewCount = BankTxMapRepository::countUnclassified();
}

$lq = ListQuery::resolve([
    'list_id' => 'ledger_moves',
    'allowed' => LedgerMovesRepository::listSortMap(),
    'default' => 'dt',
    'default_dir' => 'desc',
    'per_page' => 50,
]);
$filters = array_merge([
    'q' => $q,
    'account_id' => $accountId > 0 ? (string)$accountId : '',
    'type_id' => $typeId > 0 ? (string)$typeId : '',
    'year' => $year > 0 ? (string)$year : '',
    'month' => $month > 0 ? (string)$month : '',
    'payment_id' => $paymentId > 0 ? (string)$paymentId : '',
    'supplier_id' => $supplierId > 0 ? (string)$supplierId : '',
    'promise' => $promise > 0 ? (string)$promise : '',
    'bank_review' => $bankReview > 0 ? (string)$bankReview : '',
], $lq['query']);
if (!empty($lq['show_all'])) {
    $filters['page'] = 'all';
}
BulkActions::tryHandle('ledger_moves', '/ledger/moves', $filters);

$reconListUrl = ListQuery::url('/ledger/moves', $filters, []);
$reconError = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && (int)($_POST['set_supplier_move_id'] ?? 0) > 0) {
    if (!Auth::can('ledger.edit')) {
        if ($wantsJson) {
            AgentHttp::error(t('ledger.err.forbidden'), 403);
        }
        http_response_code(403);
        View::render('error', ['title' => t('core.common.forbidden'), 'message' => t('ledger.err.forbidden')]);
        return;
    }
    try {
        $setMoveId = (int)$_POST['set_supplier_move_id'];
        $setSupplierId = (int)($_POST['set_supplier_id'] ?? 0);
        LedgerMovesRepository::setSupplierId($setMoveId, $setSupplierId);
        if ($wantsJson) {
            AgentHttp::emit(['ok' => true, 'move_id' => $setMoveId, 'supplier_id' => $setSupplierId]);
            return;
        }
        flash_set('ok', t('ledger.moves.supplier_set_ok'));
        redirect($reconListUrl);
    } catch (Throwable $e) {
        if ($wantsJson) {
            AgentHttp::error($e->getMessage(), 400);
        }
        flash_set('warn', $e->getMessage());
        redirect($reconListUrl);
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && (string)($_POST['recon_action'] ?? '') !== '') {
    if (!Auth::can('ledger.reconcile')) {
        if ($wantsJson) {
            AgentHttp::error(t('ledger.err.forbidden'), 403);
        }
    } else {
        $action = (string)$_POST['recon_action'];
        try {
            $reconAccountId = (int)($_POST['recon_account_id'] ?? 0);
            $reconId = 0;
            if ($action === 'save') {
                $raw = LedgerReconciliationsRepository::normalizeBankBalance((string)($_POST['bank_balance'] ?? ''));
                if ($raw === '' || !is_numeric($raw)) {
                    throw new InvalidArgumentException(t('ledger.recon.err.bank'));
                }
                $reconId = LedgerReconciliationsRepository::create($reconAccountId, (float)$raw, Auth::userId());
            } elseif ($action === 'update') {
                $raw = LedgerReconciliationsRepository::normalizeBankBalance((string)($_POST['bank_balance'] ?? ''));
                if ($raw === '' || !is_numeric($raw)) {
                    throw new InvalidArgumentException(t('ledger.recon.err.bank'));
                }
                $reconId = (int)($_POST['recon_id'] ?? 0);
                LedgerReconciliationsRepository::updateBank($reconId, $reconAccountId, (float)$raw);
            } elseif ($action === 'delete') {
                $reconId = (int)($_POST['recon_id'] ?? 0);
                LedgerReconciliationsRepository::deleteToday($reconId, $reconAccountId);
            } elseif ($action === 'disable') {
                $reconId = (int)($_POST['recon_id'] ?? 0);
                LedgerReconciliationsRepository::disable(
                    $reconId,
                    (string)($_POST['reason'] ?? ''),
                    Auth::userId()
                );
            } else {
                throw new InvalidArgumentException(t('ledger.recon.err.action'));
            }
            if ($wantsJson) {
                AgentHttp::emit([
                    'ok' => true,
                    'action' => $action,
                    'recon_id' => $reconId,
                    'account_id' => $reconAccountId,
                    'recon_today' => LedgerReconciliationsRepository::latestToday($reconAccountId),
                ]);
                return;
            }
            redirect($reconListUrl);
        } catch (Throwable $e) {
            if ($wantsJson) {
                AgentHttp::error($e->getMessage(), 400);
            }
            $reconError = $e->getMessage();
        }
    }
}

$listOpts = [
    'q' => $q,
    'account_id' => $accountId,
    'type_id' => $typeId,
    'year' => $year,
    'month' => $month,
    'payment_id' => $paymentId,
    'supplier_id' => $supplierId,
    'promise' => $promise,
    'bank_review' => $bankReview,
];
$orderDir = strtoupper((string)$lq['dir']) === 'ASC' ? 'ASC' : 'DESC';
$result = LedgerMovesRepository::list($listOpts + [
    'limit' => ListQuery::sqlLimit($lq),
    'offset' => $lq['offset'],
    'order' => $lq['order_sql'] . ', m.ID ' . $orderDir,
]);

$accounts = LedgerAccountsRepository::names();
$typeMeta = AccountMoveTypesRepository::leafMeta();
$types = [];
$typeActIn = [];
$typeSupplier = [];
foreach ($typeMeta as $tid => $meta) {
    $types[(int)$tid] = (string)$meta['name'];
    $typeActIn[(int)$tid] = !empty($meta['act_in_enabled']);
    $typeSupplier[(int)$tid] = !empty($meta['supplier_enabled']);
}
foreach ($result['items'] as $it) {
    $tid = (int)($it['type_id'] ?? 0);
    if ($tid < 1 || isset($typeActIn[$tid])) {
        continue;
    }
    $t = AccountMoveTypesRepository::find($tid);
    if ($t) {
        $types[$tid] = (string)$t['name'];
        $typeActIn[$tid] = !empty($t['act_in_enabled']);
        $typeSupplier[$tid] = !empty($t['supplier_enabled']);
    }
}
$stickersEnabled = ModuleManager::isEnabled('stickers') && class_exists('Acts2InRepository');
$suppliers = [];
$suppliersAll = [];
if (class_exists('SuppliersRepository')) {
    $suppliers = SuppliersRepository::names(true);
    $suppliersAll = SuppliersRepository::names(false);
}

// Balance-after column always (oper totalSummsArray over current filters).
// Reconciliations only when a single account is selected.
$showBalanceCol = true;
$showRecon = $accountId > 0;
$balance = $showRecon ? LedgerMovesRepository::accountBalance($accountId) : null;
$runningBalances = LedgerMovesRepository::runningBalances($listOpts);
$reconToday = null;
$recsByMoveId = [];
$orphanRecs = []; // no moves at all for filters — still show historical recons
$firstMoveId = 0; // only the globally newest move (not merely first row of a deep page)
if ($showRecon) {
    $reconToday = LedgerReconciliationsRepository::latestToday($accountId);
    $newestGlobalId = LedgerMovesRepository::newestMoveId($listOpts);
    if ($result['items'] !== []) {
        $pageNewestId = (int)$result['items'][0]['id'];
        // Recon edit / «add today» only on the true newest DPS, never on deep pages.
        if ($newestGlobalId !== null && $newestGlobalId === $pageNewestId) {
            $firstMoveId = $pageNewestId;
        }
        $pageIds = [];
        $minDt = 0;
        foreach ($result['items'] as $it) {
            $mid = (int)$it['id'];
            $pageIds[$mid] = true;
            $dt = (int)($it['dt'] ?? 0);
            if ($minDt === 0 || ($dt > 0 && $dt < $minDt)) {
                $minDt = $dt;
            }
        }
        $recons = LedgerReconciliationsRepository::listActiveSince($accountId, $minDt);
        // Attach only if the rightful owner (newest DPS with DT <= recon DT globally)
        // is on this page. If newer DPS exist on other pages — do not glue recons here.
        foreach ($recons as $rec) {
            $targetId = LedgerMovesRepository::newestMoveIdOnOrBefore($listOpts, (int)$rec['dt']);
            if ($targetId === null || !isset($pageIds[$targetId])) {
                continue;
            }
            if (!isset($recsByMoveId[$targetId])) {
                $recsByMoveId[$targetId] = [];
            }
            $recsByMoveId[$targetId][] = $rec;
        }
    } elseif ((int)$result['total'] === 0) {
        // No movements for filters — still allow viewing past recons / starting today's.
        $orphanRecs = LedgerReconciliationsRepository::listActiveSince($accountId, 0);
        if (count($orphanRecs) > 20) {
            $orphanRecs = array_slice($orphanRecs, -20);
        }
    }
}

$newQs = [];
if ($accountId > 0) {
    $newQs['account_id'] = (string)$accountId;
}
if ($typeId > 0) {
    $newQs['type_id'] = (string)$typeId;
}
if ($supplierId > 0) {
    $newQs['supplier_id'] = (string)$supplierId;
}
if ($paymentId > 0) {
    $newQs['payment_id'] = (string)$paymentId;
}
$newHref = '/ledger/moves/new' . ($newQs !== [] ? ('?' . http_build_query($newQs)) : '');

$moveIds = [];
$typeByMove = [];
foreach ($result['items'] as $it) {
    $mid = (int)($it['id'] ?? 0);
    if ($mid > 0) {
        $moveIds[] = $mid;
        $typeByMove[$mid] = (int)($it['type_id'] ?? 0);
    }
}
$badgePayload = EventBus::emit('ledger.moves.badges', [
    'ids' => $moveIds,
    'type_by_move' => $typeByMove,
    'by_id' => [],
]);
$moveBadges = is_array($badgePayload['by_id'] ?? null) ? $badgePayload['by_id'] : [];
if ($moveBadges !== [] && ModuleManager::isEnabled('banks')) {
    Assets::need('banks');
}

$supplierSuggestByMove = [];
$needSuggest = [];
foreach ($result['items'] as $it) {
    $mid = (int)($it['id'] ?? 0);
    if ($mid < 1) {
        continue;
    }
    $tid = (int)($it['type_id'] ?? 0);
    if ((int)($it['supplier_id'] ?? 0) > 0) {
        continue;
    }
    if (empty($typeSupplier[$tid])) {
        continue;
    }
    $needSuggest[] = [
        'id' => $mid,
        'type_id' => $tid,
        'account_id' => (int)($it['account_id'] ?? 0),
        'summ' => (float)($it['summ'] ?? 0),
    ];
}
if ($needSuggest !== []) {
    $supplierSuggestByMove = LedgerMovesRepository::suggestSuppliersForMoves($needSuggest, 3);
}

$listToolbarHtml = '';
if ($accountId > 0) {
    $toolbarPayload = EventBus::emit('ledger.moves.list.toolbar', [
        'account_id' => $accountId,
        'filters' => $filters,
        'html' => [],
    ]);
    $chunks = is_array($toolbarPayload['html'] ?? null) ? $toolbarPayload['html'] : [];
    $listToolbarHtml = implode('', array_map('strval', $chunks));
}

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'total' => $result['total'],
        'items' => $result['items'],
        'filters' => [
            'q' => $q,
            'account_id' => $accountId,
            'type_id' => $typeId,
            'year' => $year,
            'month' => $month,
            'payment_id' => $paymentId,
            'supplier_id' => $supplierId,
            'promise' => $promise,
            'bank_review' => $bankReview,
        ],
        'balance' => $balance,
        'running_balances' => $runningBalances,
        'show_recon' => $showRecon,
        'recon_today' => $reconToday,
        'recs_by_move_id' => $recsByMoveId,
        'orphan_recs' => $orphanRecs,
        'can_recon' => Auth::can('ledger.reconcile'),
        'can_edit' => Auth::can('ledger.edit'),
        'badges' => $moveBadges,
        'supplier_suggestions' => $supplierSuggestByMove,
        'toolbar' => $listToolbarHtml !== '',
    ]);
    return;
}

$pageFlash = flash_take();

View::render('moves_list', [
    'title' => t('ledger.menu.moves'),
    'breadcrumbs' => Breadcrumbs::withHome([['label' => t('ledger.menu.moves'), 'current' => true]]),
    'items' => $result['items'],
    'total' => $result['total'],
    'q' => $q,
    'accountId' => $accountId,
    'typeId' => $typeId,
    'year' => $year,
    'month' => $month,
    'paymentId' => $paymentId,
    'supplierId' => $supplierId,
    'promise' => $promise,
    'bankReview' => $bankReview,
    'banksFilterAvailable' => $banksFilterAvailable,
    'bankReviewCount' => $bankReviewCount,
    'accounts' => $accounts,
    'types' => $types,
    'typeActIn' => $typeActIn,
    'typeSupplier' => $typeSupplier,
    'stickersEnabled' => $stickersEnabled,
    'suppliers' => $suppliers,
    'suppliersAll' => $suppliersAll,
    'balance' => $balance,
    'runningBalances' => $runningBalances,
    'showBalanceCol' => $showBalanceCol,
    'showRecon' => $showRecon,
    'reconToday' => $reconToday,
    'recsByMoveId' => $recsByMoveId,
    'orphanRecs' => $orphanRecs,
    'firstMoveId' => $firstMoveId,
    'newHref' => $newHref,
    'canEdit' => Auth::can('ledger.edit'),
    'canRecon' => Auth::can('ledger.reconcile'),
    'years' => range((int)date('Y'), (int)date('Y') - 8),
    'sort' => $lq['sort'],
    'dir' => $lq['dir'],
    'page' => $lq['page'],
    'perPage' => $lq['per_page'],
    'showAll' => $lq['show_all'],
    'filters' => $filters,
    'bulkFlash' => BulkActions::consumeFlash(),
    'reconError' => $reconError,
    'moveBadges' => $moveBadges,
    'supplierSuggestByMove' => $supplierSuggestByMove,
    'listToolbarHtml' => $listToolbarHtml,
    'pageFlash' => $pageFlash,
], 'layout', 'ledger');
