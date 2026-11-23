<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$id = (int)(Router::param('id') ?? 0);
$isNew = $id < 1;
$row = $isNew ? [
    'id' => 0,
    'operator_id' => (int)($_GET['operator_id'] ?? 0),
    'period_ym' => (string)($_GET['period_ym'] ?? date('Y-m')),
    'plan_sum' => 0.0,
    'norm_rub_hour' => 0.0,
    'bonus_tiers' => [],
    'bonus_tiers_raw' => "[]",
    'note' => '',
] : StaffPlansRepository::find($id);

if (!$isNew && $row === null) {
    if ($wantsJson) {
        AgentHttp::error(t('rates.plans.err.not_found'), 404);
        return;
    }
    flash_set('error', t('rates.plans.err.not_found'));
    redirect('/rates/plans');
}

$message = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string)($_POST['action'] ?? 'save');
    if ($action === 'delete' && !$isNew) {
        StaffPlansRepository::delete($id);
        if ($wantsJson) {
            AgentHttp::emit(['ok' => true, 'deleted' => $id]);
            return;
        }
        flash_set('ok', t('rates.plans.deleted'));
        redirect('/rates/plans');
    }
    $post = [
        'operator_id' => (int)($_POST['operator_id'] ?? 0),
        'period_ym' => (string)($_POST['period_ym'] ?? ''),
        'plan_sum' => (float)($_POST['plan_sum'] ?? 0),
        'norm_rub_hour' => (float)($_POST['norm_rub_hour'] ?? 0),
        'bonus_tiers' => (string)($_POST['bonus_tiers'] ?? ''),
        'note' => (string)($_POST['note'] ?? ''),
    ];
    try {
        $id = StaffPlansRepository::save($isNew ? null : $id, $post);
        $isNew = false;
        $row = StaffPlansRepository::find($id);
        $message = t('rates.plans.saved');
        if ($wantsJson) {
            AgentHttp::emit(['ok' => true, 'id' => $id, 'item' => $row]);
            return;
        }
        redirect('/rates/plans/' . $id . '/edit');
    } catch (Throwable $e) {
        $error = $e->getMessage();
        $row = array_merge(is_array($row) ? $row : [], $post);
        $row['bonus_tiers_raw'] = (string)($post['bonus_tiers'] ?? '');
        if ($wantsJson) {
            AgentHttp::error($error, 400);
            return;
        }
    }
}

$staff = [];
if (class_exists('UsersRepository', false)) {
    foreach (UsersRepository::listEnabledStaff() as $u) {
        $staff[(int)$u['id']] = (string)$u['name'];
    }
}

if ($wantsJson) {
    AgentHttp::emit(['ok' => true, 'is_new' => $isNew, 'item' => $row, 'staff' => $staff]);
    return;
}

View::render('plans_edit', [
    'title' => $isNew ? t('rates.plans.new') : t('rates.plans.title') . ' #' . $id,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('rates.menu.list'), 'href' => '/rates'],
        ['label' => t('rates.plans.title'), 'href' => '/rates/plans'],
        ['label' => $isNew ? t('rates.plans.new') : ('#' . $id), 'current' => true],
    ]),
    'isNew' => $isNew,
    'row' => is_array($row) ? $row : [],
    'staff' => $staff,
    'message' => $message,
    'error' => $error,
], 'layout', 'rates');
