<?php
declare(strict_types=1);

/**
 * Agent / assistant: reassign ID_ADMIN on procedure rows (same as list bulk set_admin).
 * Confirm in groups: PARTNER_STA (SUPERADMIN always; partner / partner-candidate via ID_STATUS2).
 *
 * Body: id_admin (target) + either ids, or from_id_admin (+ optional date, default today)
 * for «перекинь все процедуры от X на Y» (current day only).
 */
$wantsJson = AgentHttp::wantsJson();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    if ($wantsJson) {
        AgentHttp::error(t('procedures.err.set_admin_post'), 405);
        return;
    }
    http_response_code(405);
    View::render('error', [
        'title' => t('core.error.title'),
        'message' => t('procedures.err.set_admin_post'),
    ]);
    return;
}

$ids = [];
$rawIds = $_POST['ids'] ?? $_POST['id'] ?? null;
if (is_array($rawIds)) {
    foreach ($rawIds as $v) {
        $ids[] = (int)$v;
    }
} elseif (is_string($rawIds) || is_int($rawIds) || is_float($rawIds)) {
    foreach (preg_split('/[\s,;]+/', trim((string)$rawIds)) ?: [] as $part) {
        if ($part !== '' && ctype_digit($part)) {
            $ids[] = (int)$part;
        }
    }
}
$ids = array_values(array_unique(array_filter($ids, static function (int $id): bool {
    return $id > 0;
})));

$adminId = (int)($_POST['id_admin'] ?? $_POST['admin_id'] ?? 0);
$fromAdmin = (int)($_POST['from_id_admin'] ?? $_POST['from_admin'] ?? 0);
$date = trim((string)($_POST['date'] ?? $_POST['date_from'] ?? ''));
if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $date = date('Y-m-d');
}
$resolvedFromFilter = false;
if ($ids === [] && $fromAdmin > 0) {
    $ids = ProceduresRepository::idsByAdminOnDate($fromAdmin, $date);
    $resolvedFromFilter = true;
}

if ($adminId < 1) {
    if ($wantsJson) {
        AgentHttp::error(t('procedures.bulk.err.admin_required'), 400);
        return;
    }
    flash_set('err', t('procedures.bulk.err.admin_required'));
    redirect('/procedures');
    return;
}
if ($ids === []) {
    $err = $resolvedFromFilter || $fromAdmin > 0
        ? t('procedures.err.set_admin_none_today', [
            'admin' => class_exists('UsersRepository', false)
                ? (string)(UsersRepository::nameById($fromAdmin) ?: ('#' . $fromAdmin))
                : ('#' . $fromAdmin),
            'date' => $date,
        ])
        : t('procedures.err.set_admin_ids');
    if ($wantsJson) {
        AgentHttp::error($err, 400);
        return;
    }
    flash_set('err', $err);
    redirect('/procedures');
    return;
}

$missing = [];
$found = [];
foreach ($ids as $id) {
    $row = ProceduresRepository::find($id);
    if ($row === null) {
        $missing[] = $id;
    } else {
        $found[] = $row;
    }
}
if ($missing !== []) {
    if ($wantsJson) {
        AgentHttp::error(t('procedures.err.set_admin_missing', [
            'ids' => implode(', ', array_map('strval', $missing)),
        ]), 404);
        return;
    }
    flash_set('err', t('procedures.err.set_admin_missing', [
        'ids' => implode(', ', array_map('strval', $missing)),
    ]));
    redirect('/procedures');
    return;
}

$done = ProceduresRepository::setAdminByIds($ids, $adminId);
$adminName = class_exists('UsersRepository', false)
    ? (string)(UsersRepository::nameById($adminId) ?: ('#' . $adminId))
    : ('#' . $adminId);

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'done' => $done,
        'ids' => $ids,
        'id_admin' => $adminId,
        'admin_name' => $adminName,
        'from_id_admin' => $fromAdmin > 0 ? $fromAdmin : null,
        'date' => $resolvedFromFilter ? $date : null,
        'items' => array_map(static function (array $r): array {
            return [
                'id' => (int)($r['id'] ?? 0),
                'id_admin' => (int)($r['id_admin'] ?? 0),
                'id_operator' => (int)($r['id_operator'] ?? 0),
            ];
        }, $found),
    ]);
    return;
}

flash_set('ok', t('procedures.flash.set_admin_done', [
    'n' => (string)$done,
    'admin' => $adminName,
]));
redirect('/procedures');
