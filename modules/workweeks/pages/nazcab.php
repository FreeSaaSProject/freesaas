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

$tablesOn = WorkweeksRepository::tablesActive();
$badgesOn = class_exists('BadgeAssignmentsRepository', false)
    && ModuleManager::isEnabled('audio')
    && Database::tableExists('base_badge_assignments');
if (!$tablesOn && !$badgesOn) {
    if ($wantsJson) {
        AgentHttp::error(t('workweeks.nazcab.err.tables_off'), 400);
        return;
    }
    http_response_code(400);
    echo '<div class="flash flash-warn">' . e(t('workweeks.nazcab.err.tables_off')) . '</div>';
    return;
}

$retRaw = (string)($_POST['ret'] ?? $_GET['ret'] ?? '');
$retUrl = NavContext::sanitize($retRaw);
if ($retUrl === '') {
    $retUrl = '/workweeks';
}
$isAjax = !empty($_GET['fragment'])
    || (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['nazcab_action'] ?? '') === 'save') {
    try {
        $tables = [];
        foreach ((array)($_POST['table'] ?? []) as $d => $byOp) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$d) || !is_array($byOp)) {
                continue;
            }
            foreach ($byOp as $oid => $tid) {
                $oid = (int)$oid;
                if ($oid > 0) {
                    $tables[(string)$d][$oid] = (int)$tid;
                }
            }
        }
        $badges = [];
        foreach ((array)($_POST['badge'] ?? []) as $d => $byOp) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$d) || !is_array($byOp)) {
                continue;
            }
            foreach ($byOp as $oid => $code) {
                $oid = (int)$oid;
                if ($oid > 0) {
                    $badges[(string)$d][$oid] = trim((string)$code);
                }
            }
        }
        $msg = WorkweeksRepository::saveNazcabMatrix($tables, $badges);
        $sep = strpos($retUrl, '?') === false ? '?' : '&';
        $redirectTo = $retUrl . $sep . 'shift_ok=1';
        if ($wantsJson) {
            AgentHttp::emit([
                'ok' => true,
                'message' => $msg,
                'today' => date('Y-m-d'),
                'tables_on' => $tablesOn,
                'badges_on' => $badgesOn,
            ]);
            return;
        }
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => true, 'redirect' => $redirectTo, 'message' => $msg], JSON_UNESCAPED_UNICODE);
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

// Multi-day matrix for badges; tables (sections) only on today's row in the view.
$mode = trim((string)($_GET['day'] ?? $_GET['mode'] ?? 'all'));
$dayCount = 1;
$startTs = strtotime(date('Y-m-d 00:00:00')) ?: time();

if ($mode === 'all') {
    $dayCount = 30;
} elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $mode)) {
    // Anchor on requested day, but always include a forward window for badges.
    $req = strtotime($mode . ' 00:00:00');
    if ($req !== false) {
        $startTs = min($startTs, (int)$req);
    }
    $dayCount = 30;
    $mode = 'all';
} else {
    $mode = 'all';
    $dayCount = 30;
}

$matrix = WorkweeksRepository::nazcabMatrix((int)$startTs, $dayCount);

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'today' => date('Y-m-d'),
        'tables_on' => $tablesOn,
        'badges_on' => $badgesOn,
        'tables_editable_date' => date('Y-m-d'),
        'matrix' => $matrix,
    ]);
    return;
}

ob_start();
$formAction = '/workweeks/nazcab';
require base_path('modules/workweeks/views/_nazcab.php');
$html = ob_get_clean();

if ($isAjax || !empty($_GET['fragment'])) {
    header('Content-Type: text/html; charset=utf-8');
    echo $html;
    return;
}

View::render('nazcab_page', [
    'title' => t('workweeks.nazcab.title'),
    'html' => $html,
    'main_flush' => true,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('workweeks.menu.list'), 'href' => '/workweeks'],
        ['label' => t('workweeks.nazcab.title'), 'current' => true],
    ]),
], 'layout', 'workweeks');
