<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$id = (int)(Router::param('id') ?? 0);
$isNew = $id < 1;
$row = [
    'id' => 0, 'fund_id' => (int)($_GET['fund_id'] ?? 0), 'name' => '', 'kind' => 'percent', 'percent' => 0.0,
    'fixed_sum' => 0.0, 'period' => 'week', 'base_mode' => 'all_income', 'base_types' => [],
    'dt_from' => 0, 'dt_to' => 0, 'enabled' => true, 'note' => '',
];
if (!$isNew) {
    $found = FundRulesRepository::find($id);
    if ($found === null) {
        if ($wantsJson) {
            AgentHttp::error(t('funds.rules.err.not_found', ['id' => $id]), 404);
            return;
        }
        http_response_code(404);
        View::render('error', ['title' => t('core.common.not_found'), 'message' => t('funds.rules.err.not_found', ['id' => $id])]);
        return;
    }
    $row = $found;
}

if (!$isNew && EntityDelete::tryHandle('fund_rules', $id, '/funds/rules', ['stay_path' => '/funds/rules/' . $id . '/edit'])) {
    return;
}

$message = '';
$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $input = $wantsJson ? AgentOps::requestInput() : $_POST;
    if (!$wantsJson) {
        // HTML checkbox: absent = off
        $input['enabled'] = !empty($input['enabled']) ? 1 : 0;
        $input['base_types'] = $input['base_types'] ?? [];
    }
    $res = FundsAgentOps::rulesSave($input, $id);
    if ($wantsJson) {
        AgentHttp::emit($res, (int)($res['http'] ?? 200));
        return;
    }
    if (!empty($res['ok'])) {
        redirect('/funds/rules/' . (int)$res['id'] . '/edit?saved=1');
        return;
    }
    $error = (string)($res['error'] ?? '');
    $row = array_merge($row, $input);
    $row['base_types'] = is_array($row['base_types']) ? $row['base_types'] : preg_split('/[\s,;]+/', (string)$row['base_types'], -1, PREG_SPLIT_NO_EMPTY);
}
if (isset($_GET['saved']) && $message === '') {
    $message = t('core.common.saved');
}

$fundOptions = [];
foreach (FundsRepository::leafOptions() as $g) {
    $opts = [];
    foreach ($g['options'] as $fid => $fname) {
        $f = FundsRepository::allRows()[(int)$fid] ?? null;
        if ($f !== null && (int)$f['type'] === 2) {
            $opts[(int)$fid] = $fname;
        }
    }
    if ($opts !== []) {
        $fundOptions[] = ['label' => $g['label'], 'options' => $opts];
    }
}
$typeNames = class_exists('AccountMoveTypesRepository') ? AccountMoveTypesRepository::names(true) : [];

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'is_new' => $isNew,
        'item' => $row,
        'fund_options' => $fundOptions,
        'type_options' => $typeNames,
    ]);
    return;
}

View::render('rules_edit', [
    'title' => $isNew ? t('funds.rules.edit_new') : t('funds.rules.edit', ['id' => $id]),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('funds.menu.list'), 'href' => '/funds'],
        ['label' => t('funds.menu.rules'), 'href' => '/funds/rules'],
        ['label' => $isNew ? t('funds.rules.edit_new') : ('#' . $id), 'current' => true],
    ]),
    'isNew' => $isNew,
    'row' => $row,
    'fundOptions' => $fundOptions,
    'typeNames' => $typeNames,
    'message' => $message,
    'error' => $error,
], 'layout', 'funds');
