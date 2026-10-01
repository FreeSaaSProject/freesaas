<?php
declare(strict_types=1);

if (!WorkweeksRepository::canAssign()) {
    http_response_code(403);
    echo '<div class="flash flash-warn">' . e(t('workweeks.err.no_assign')) . '</div>';
    return;
}

$retUrl = EventsRepository::sanitizeReturnUrl((string)($_POST['ret'] ?? $_GET['ret'] ?? '/calendar'));
$myOnly = !empty(Auth::statusFlags()['EVT_MY_ONLY']);

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
        WorkweeksRepository::saveNazopMatrix($checked, $prices, $masMax, $ops);
        $sep = strpos($retUrl, '?') === false ? '?' : '&';
        redirect($retUrl . $sep . 'shift_ok=1');
        return;
    } catch (Throwable $e) {
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
if ($opsParam !== '') {
    foreach (explode(',', $opsParam) as $id) {
        $id = (int)$id;
        if ($id > 0) {
            $opsFilter[] = $id;
        }
    }
} elseif ($myOnly) {
    $opsFilter = [Auth::userId()];
} elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $mode)) {
    foreach (WorkweeksRepository::listForDay((int)$startTs) as $sh) {
        $oid = (int)$sh['id_operator'];
        if ($oid > 0) {
            $opsFilter[] = $oid;
        }
    }
    if ($opsFilter === []) {
        $opsFilter = [Auth::userId()];
    }
} else {
    $opsFilter = [Auth::userId()];
}
$opsFilter = array_values(array_unique($opsFilter));

$matrix = WorkweeksRepository::nazopMatrix((int)$startTs, $dayCount, $opsFilter);
$availableOps = WorkweeksRepository::listAssignableStaff();
$canManageOps = !$myOnly && Auth::capability('EVT_ASSIGN');

$fragment = !empty($_GET['fragment']) || (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])
    && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');

ob_start();
$formAction = '/calendar/nazop';
require base_path('modules/workweeks/views/_nazop.php');
$html = ob_get_clean();

if ($fragment) {
    header('Content-Type: text/html; charset=utf-8');
    echo $html;
    return;
}

View::render('nazop_page', [
    'title' => t('workweeks.nazop.title'),
    'html' => $html,
    'main_flush' => true,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('calendar.menu.week'), 'href' => '/calendar'],
        ['label' => t('workweeks.nazop.title'), 'current' => true],
    ]),
], 'layout', 'calendar');
