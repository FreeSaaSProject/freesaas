<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

$q = trim((string)($_GET['q'] ?? ''));
$userId = (int)($_GET['user_id'] ?? 0);
$typeId = (int)($_GET['type_id'] ?? 0);
$accountRaw = $_GET['account'] ?? '';
$directionRaw = $_GET['direction'] ?? '';
$account = $accountRaw === '' ? null : (int)$accountRaw;
$direction = $directionRaw === '' ? null : (int)$directionRaw;

$isSuperadmin = Auth::capability('SUPERADMIN_ACCESS');
$modeRaw = strtolower(trim((string)($_GET['mode'] ?? '')));
$mode = 'monthly';
if ($isSuperadmin && ($modeRaw === 'linear' || $modeRaw === '1')) {
    $mode = 'linear';
} elseif ($modeRaw === 'monthly' || $modeRaw === '2') {
    $mode = 'monthly';
}

$year = (int)($_GET['year'] ?? 0);
$month = (int)($_GET['month'] ?? 0);
if ($mode === 'monthly') {
    if ($year < 2000 || $year > 2100) {
        $year = (int)date('Y');
    }
    if ($month < 0 || $month > 12) {
        $month = 0;
    }
} else {
    if ($year < 2000 || $year > 2100) {
        $year = 0;
    }
    if ($month < 1 || $month > 12) {
        $month = 0;
    }
}

$canSeeOthers = PaymentsRepository::canSeeOthers();
$scopeUserId = $canSeeOthers ? 0 : Auth::userId();
if (!$canSeeOthers) {
    $userId = Auth::userId();
}

$dayPayMessage = '';
$dayPayError = '';
$me = Auth::userId();
$isCashIssuer = class_exists('DayPayroll', false) && $me > 0 && DayPayroll::isCashIssuer($me);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && class_exists('DayPayroll', false)) {
    $action = (string)($_POST['day_pay_action'] ?? '');
    if ($action !== '') {
        try {
            if ($action === 'request') {
                if (!DayPayroll::isDailyPayStaff($me) || ($canSeeOthers && $userId !== $me)) {
                    throw new RuntimeException(t('payments.bot.day_pay.only_daily'));
                }
                $slot = (string)($_POST['payout_slot'] ?? '');
                $dayTs = 0;
                $issuerId = 0;
                if (preg_match('/^(\d+)_(\d+)$/', $slot, $m)) {
                    $dayTs = (int)$m[1];
                    $issuerId = (int)$m[2];
                } else {
                    $dayTs = (int)($_POST['payout_day'] ?? 0);
                    $issuerId = (int)($_POST['issuer_id'] ?? 0);
                }
                $res = DayPayroll::createRequest($me, $dayTs, $me, $issuerId);
                if (empty($res['ok'])) {
                    throw new RuntimeException((string)($res['msg'] ?? t('payments.bot.day_pay.unavailable')));
                }
                $dayPayMessage = (string)$res['msg'];
                if ($wantsJson) {
                    AgentHttp::emit(['ok' => true, 'action' => 'day_pay_request', 'result' => $res]);
                    return;
                }
            } elseif ($action === 'cancel') {
                $payId = (int)($_POST['payment_id'] ?? 0);
                $res = DayPayroll::cancelRequest($payId, $me);
                if (empty($res['ok'])) {
                    throw new RuntimeException((string)($res['msg'] ?? t('payments.bot.day_pay.not_found')));
                }
                $dayPayMessage = (string)$res['msg'];
                if ($wantsJson) {
                    AgentHttp::emit(['ok' => true, 'action' => 'day_pay_cancel', 'result' => $res]);
                    return;
                }
            } elseif ($action === 'verify_pin') {
                if (!$isCashIssuer) {
                    throw new RuntimeException(t('payments.bot.day_pay.unavailable'));
                }
                $payId = (int)($_POST['payment_id'] ?? 0);
                $pin = (string)($_POST['pin'] ?? '');
                $res = DayPayroll::verifyPin($payId, $pin, $me);
                if (empty($res['ok'])) {
                    throw new RuntimeException((string)($res['msg'] ?? t('payments.bot.day_pay.bad_pin')));
                }
                $dayPayMessage = (string)$res['msg'];
                if ($wantsJson) {
                    AgentHttp::emit(['ok' => true, 'action' => 'day_pay_verify_pin', 'result' => $res]);
                    return;
                }
            }
        } catch (Throwable $e) {
            AgentHttp::rethrowIfCapture($e);
            if ($wantsJson) {
                AgentHttp::error($e->getMessage(), 400);
                return;
            }
            $dayPayError = $e->getMessage();
        }
    }
}

$lq = ListQuery::resolve([
    'list_id' => 'payments',
    'allowed' => PaymentsRepository::listSortMap(),
    'default' => 'dt',
    'default_dir' => 'desc',
    'per_page' => 50,
]);

$filters = [
    'q' => $q,
    'user_id' => $userId > 0 ? (string)$userId : '',
    'type_id' => $typeId > 0 ? (string)$typeId : '',
    'account' => $accountRaw !== '' ? (string)$account : '',
    'direction' => $directionRaw !== '' ? (string)$direction : '',
    'mode' => $mode,
];
if ($year > 0) {
    $filters['year'] = (string)$year;
}
if ($month > 0) {
    $filters['month'] = (string)$month;
}
$filters = array_merge($filters, $lq['query']);
if ($mode === 'monthly' || !empty($lq['show_all'])) {
    $filters['page'] = 'all';
}

BulkActions::tryHandle('payments', '/payments', $filters);

// Eager procents → current-month accrual row when one staff is in scope and view covers this month.
$syncUserId = $canSeeOthers ? $userId : Auth::userId();
$cy = (int)date('Y');
$cm = (int)date('n');
$coversCurrentMonth = ($mode === 'monthly' && $year === $cy && ($month === 0 || $month === $cm))
    || ($mode !== 'monthly' && ($year < 1 || ($year === $cy && ($month < 1 || $month === $cm))));
if ($syncUserId > 0 && $coversCurrentMonth) {
    if (class_exists('DayPayroll', false) && DayPayroll::isDailyPayStaff($syncUserId)) {
        DayPayroll::syncOperator($syncUserId, Auth::userId());
    } elseif (ModuleManager::isEnabled('procents')) {
        $repoLib = base_path('modules/procents/lib/ProcentsRepository.php');
        $syncLib = base_path('modules/procents/lib/ProcentsPaymentSync.php');
        if (is_file($repoLib) && is_file($syncLib)) {
            require_once $repoLib;
            require_once $syncLib;
            ProcentsPaymentSync::syncStaffCurrentMonth($syncUserId);
        }
    }
}

$listOpts = [
    'q' => $q,
    'user_id' => $canSeeOthers ? $userId : 0,
    'type_id' => $typeId,
    'account' => $account,
    'direction' => $direction,
    'scope_user_id' => $scopeUserId,
];

$months = [];
$items = [];
$total = 0;
$dtFrom = 0;
$dtTo = 0;

if ($mode === 'monthly') {
    if ($month > 0) {
        $dtFrom = (int)mktime(0, 0, 0, $month, 1, $year);
        $dtTo = (int)mktime(0, 0, 0, $month + 1, 1, $year);
    } else {
        $dtFrom = (int)mktime(0, 0, 0, 1, 1, $year);
        $dtTo = (int)mktime(0, 0, 0, 1, 1, $year + 1);
    }
    $listOpts['dt_from'] = $dtFrom;
    $listOpts['dt_to'] = $dtTo;
    // Newest first in UI; groupByMonth needs chronological ASC for debt chain.
    $listOpts['order'] = 'p.DT DESC, p.ID DESC';
    $listOpts['limit'] = 0;
    $listOpts['offset'] = 0;
    $result = PaymentsRepository::list($listOpts);
    $items = $result['items'];
    $total = $result['total'];
    $openingDebt = PaymentsRepository::sumBalanceBefore(array_merge($listOpts, [
        'dt_from' => $dtFrom,
    ]));
    $months = PaymentsRepository::groupByMonth(array_reverse($items), $openingDebt);
    $months = array_reverse($months);
    foreach ($months as &$monthBlock) {
        $monthBlock['accrued'] = array_reverse($monthBlock['accrued']);
        $monthBlock['issued'] = array_reverse($monthBlock['issued']);
    }
    unset($monthBlock);
} else {
    if ($year > 0) {
        if ($month > 0) {
            $dtFrom = (int)mktime(0, 0, 0, $month, 1, $year);
            $dtTo = (int)mktime(0, 0, 0, $month + 1, 1, $year);
        } else {
            $dtFrom = (int)mktime(0, 0, 0, 1, 1, $year);
            $dtTo = (int)mktime(0, 0, 0, 1, 1, $year + 1);
        }
        $listOpts['dt_from'] = $dtFrom;
        $listOpts['dt_to'] = $dtTo;
    }
    $listOpts['limit'] = ListQuery::sqlLimit($lq);
    $listOpts['offset'] = $lq['offset'];
    $listOpts['order'] = $lq['order_sql'];
    $result = PaymentsRepository::list($listOpts);
    $items = $result['items'];
    $total = $result['total'];
}

$staff = [];
foreach (UsersRepository::listEnabledStaff() as $u) {
    $staff[(int)$u['id']] = trim((string)$u['name']) !== '' ? (string)$u['name'] : (string)$u['login'];
}
$typeNames = PaymentTypesRepository::names();
$bulkFlash = BulkActions::consumeFlash();

$yearOptions = [];
$cy = (int)date('Y');
for ($i = 0; $i < 10; $i++) {
    $yearOptions[] = $cy - $i;
}

$ledgerEnabled = PaymentsRepository::ledgerEnabled();
$canViewLedger = $ledgerEnabled && Auth::can('ledger.view');
$dpsByPayment = [];
if ($ledgerEnabled && class_exists('LedgerMovesRepository')) {
    $paymentIds = [];
    foreach ($items as $it) {
        $paymentIds[] = (int)$it['id'];
    }
    $dpsByPayment = class_exists('PaymentMoveLinksRepository', false)
        ? PaymentMoveLinksRepository::moveIdsByPaymentIds($paymentIds)
        : LedgerMovesRepository::moveIdsByPaymentIds($paymentIds);
}

if ($wantsJson) {
    $sumAccrued = 0.0;
    $sumIssued = 0.0;
    foreach ($items as $it) {
        if (!is_array($it)) {
            continue;
        }
        $s = (float)($it['sum'] ?? 0);
        if ((int)($it['direction'] ?? 0) === 1) {
            $sumAccrued += $s;
        } else {
            $sumIssued += $s;
        }
    }
    $payload = [
        'ok' => true,
        'mode' => $mode,
        'total' => $total,
        'totals' => [
            'accrued' => round($sumAccrued, 2),
            'issued' => round($sumIssued, 2),
            'count' => count($items),
            'count_accrued' => count(array_filter($items, static function ($it): bool {
                return is_array($it) && (int)($it['direction'] ?? 0) === 1;
            })),
            'count_issued' => count(array_filter($items, static function ($it): bool {
                return is_array($it) && (int)($it['direction'] ?? 0) !== 1;
            })),
        ],
        'items' => $items,
        'filters' => [
            'q' => $q,
            'user_id' => $userId > 0 ? $userId : null,
            'type_id' => $typeId > 0 ? $typeId : null,
            'account' => $account,
            'direction' => $direction,
            'year' => $year > 0 ? $year : null,
            'month' => $month > 0 ? $month : null,
        ],
        'can_see_others' => $canSeeOthers,
    ];
    if ($mode === 'monthly') {
        $payload['months'] = $months;
        $payload['opening_debt'] = $openingDebt ?? null;
    } else {
        $payload['page'] = $lq['page'];
        $payload['per_page'] = $lq['per_page'];
        $payload['show_all'] = !empty($lq['show_all']);
    }
    if ($ledgerEnabled) {
        $payload['dps_by_payment'] = $dpsByPayment;
    }

    $dayPayPanel = class_exists('DayPayroll', false)
        ? DayPayroll::panelForList($me, $userId, $canSeeOthers, $dayPayMessage, $dayPayError)
        : null;
    if ($dayPayPanel !== null) {
        $payload['day_pay'] = $dayPayPanel;
    }
    AgentHttp::emit($payload);
    return;
}

$dayPayPanel = class_exists('DayPayroll', false)
    ? DayPayroll::panelForList($me, $userId, $canSeeOthers, $dayPayMessage, $dayPayError)
    : null;
$actionUrl = ListQuery::url('/payments', $filters, []);

View::render('list', [
    'title' => t('payments.menu.list'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('payments.menu.list'), 'current' => true],
    ]),
    'mode' => $mode,
    'isSuperadmin' => $isSuperadmin,
    'year' => $year,
    'month' => $month,
    'yearOptions' => $yearOptions,
    'months' => $months,
    'items' => $items,
    'total' => $total,
    'q' => $q,
    'userId' => $userId,
    'typeId' => $typeId,
    'account' => $account,
    'direction' => $direction,
    'staff' => $staff,
    'typeNames' => $typeNames,
    'canSeeOthers' => $canSeeOthers,
    'canEdit' => Auth::can('payments.edit'),
    'ledgerEnabled' => $ledgerEnabled,
    'canViewLedger' => $canViewLedger,
    'dpsByPayment' => $dpsByPayment,
    'sort' => $lq['sort'],
    'dir' => $lq['dir'],
    'page' => $lq['page'],
    'perPage' => $lq['per_page'],
    'showAll' => $mode === 'monthly' || !empty($lq['show_all']),
    'filters' => $filters,
    'bulkFlash' => $bulkFlash,
    'dayPayPanel' => $dayPayPanel,
    'actionUrl' => $actionUrl,
], 'layout', 'payments');
