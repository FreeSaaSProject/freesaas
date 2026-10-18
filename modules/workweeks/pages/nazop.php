<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

if (!WorkweeksRepository::canAssign()) {
    if ($wantsJson) {
        AgentHttp::error(t('workweeks.err.no_assign'), 403);
        return;
    }
    http_response_code(403);
    echo '<div class="flash flash-warn">' . e(t('workweeks.err.no_assign')) . '</div>';
    return;
}

$retRaw = (string)($_POST['ret'] ?? $_GET['ret'] ?? '');
$retUrl = NavContext::sanitize($retRaw);
if ($retUrl === '') {
    $retUrl = '/workweeks';
}
$myOnly = !empty(Auth::statusFlags()['EVT_MY_ONLY']);
$isAjax = !empty($_GET['fragment'])
    || (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['nazop_action'] ?? '') === 'save') {
    try {
        $ops = array_map('intval', (array)($_POST['ops'] ?? []));
        if ($myOnly) {
            $ops = [Auth::userId()];
        }
        $checked = (array)($_POST['checked'] ?? []);
        $prices = [];
        foreach ((array)($_POST['price'] ?? []) as $d => $v) {
            $prices[(string)$d] = (int)$v;
        }
        $masMax = [];
        foreach ((array)($_POST['mas_max'] ?? []) as $d => $v) {
            $masMax[(string)$d] = (int)$v;
        }
        $opPrices = [];
        foreach ((array)($_POST['op_price'] ?? []) as $d => $byOp) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$d) || !is_array($byOp)) {
                continue;
            }
            foreach ($byOp as $oid => $pc) {
                $oid = (int)$oid;
                if ($oid > 0) {
                    $opPrices[(string)$d][$oid] = (int)$pc;
                }
            }
        }
        $msg = WorkweeksRepository::saveNazopMatrix($checked, $prices, $masMax, $ops, $opPrices);
        $sep = strpos($retUrl, '?') === false ? '?' : '&';
        $redirectTo = $retUrl . $sep . 'shift_ok=1';
        if ($wantsJson) {
            AgentHttp::emit([
                'ok' => true,
                'saved' => true,
                'message' => $msg,
                'redirect' => $redirectTo,
            ]);
            return;
        }
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => true, 'redirect' => $redirectTo], JSON_UNESCAPED_UNICODE);
            return;
        }
        redirect($redirectTo);
        return;
    } catch (Throwable $e) {
        if ($wantsJson) {
            AgentHttp::error($e->getMessage(), 400);
            return;
        }
        if ($isAjax) {
            http_response_code(400);
            header('Content-Type: text/html; charset=utf-8');
            echo '<div class="flash flash-warn">' . e($e->getMessage()) . '</div>';
            return;
        }
        http_response_code(400);
        echo '<div class="flash flash-warn">' . e($e->getMessage()) . '</div>';
        return;
    }
}

$mode = trim((string)($_GET['day'] ?? $_GET['mode'] ?? 'all'));
$dayCount = 1;
$startTs = time();

if ($mode === 'all') {
    $dayCount = 60;
    if (!Auth::capability('SUPERADMIN_ACCESS')) {
        $startTs = strtotime('tomorrow 00:00:00') ?: (time() + 86400);
    } else {
        $startTs = strtotime('today 00:00:00') ?: time();
    }
} elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $mode)) {
    $startTs = strtotime($mode . ' 00:00:00') ?: time();
    $dayCount = 1;
} else {
    $mode = 'all';
    $dayCount = 60;
    $startTs = strtotime('today 00:00:00') ?: time();
}

$opsFilter = [];
$opsParam = trim((string)($_GET['ops'] ?? ''));
$selfId = Auth::userId();
if ($opsParam !== '') {
    foreach (explode(',', $opsParam) as $id) {
        $id = (int)$id;
        if ($id > 0 && WorkweeksRepository::canAssignOperator($id)) {
            $opsFilter[] = $id;
        }
    }
} elseif ($myOnly) {
    $opsFilter = [$selfId];
} elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $mode)) {
    foreach (WorkweeksRepository::listForDay((int)$startTs) as $sh) {
        $oid = (int)$sh['id_operator'];
        if ($oid > 0 && WorkweeksRepository::canAssignOperator($oid)) {
            $opsFilter[] = $oid;
        }
    }
    if ($selfId > 0 && WorkweeksRepository::canAssignOperator($selfId)) {
        $opsFilter[] = $selfId;
    }
    if ($opsFilter === []) {
        $opsFilter = [$selfId];
    }
} else {
    $opsFilter = [$selfId];
}
$opsFilter = array_values(array_unique(array_filter($opsFilter)));

$matrix = WorkweeksRepository::nazopMatrix((int)$startTs, $dayCount, $opsFilter);
$availableOps = WorkweeksRepository::listAssignableStaff();
$canManageOps = !$myOnly && $availableOps !== [];

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'mode' => $mode,
        'day_count' => $dayCount,
        'start_ts' => (int)$startTs,
        'ops_filter' => $opsFilter,
        'matrix' => $matrix,
        'available_ops' => $availableOps,
        'can_manage_ops' => $canManageOps,
        'shift_windows' => WorkweeksRepository::shiftWindows(),
        'hint' => 'POST nazop_action=save with ops[], checked[YYYY-MM-DD][opId]=1, '
            . 'op_price[YYYY-MM-DD][opId]=PRICE_COL, optional price[date]=day default, mas_max[date]. '
            . 'Existing rows keep price_col unless op_price is sent. New checks use status DEFAULT_PRICE_COL then day default.',
    ]);
    return;
}

ob_start();
$formAction = '/workweeks/nazop';
require base_path('modules/workweeks/views/_nazop.php');
$html = ob_get_clean();

if ($isAjax || !empty($_GET['fragment'])) {
    header('Content-Type: text/html; charset=utf-8');
    echo $html;
    return;
}

View::render('nazop_page', [
    'title' => t('workweeks.nazop.title'),
    'html' => $html,
    'main_flush' => true,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('workweeks.menu.list'), 'href' => '/workweeks'],
        ['label' => t('workweeks.nazop.title'), 'current' => true],
    ]),
], 'layout', 'workweeks');
