<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$canEdit = Auth::can('funds.edit');
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $canEdit) {
    $input = $wantsJson ? AgentOps::requestInput() : $_POST;
    $action = (string)($input['map_action'] ?? 'save');
    if (!$wantsJson && $action === 'save') {
        $input['enabled'] = !empty($input['enabled']) ? 1 : 0;
    }
    $res = $action === 'delete' ? FundsAgentOps::typeMapDelete($input) : FundsAgentOps::typeMapSave($input);
    if ($wantsJson) {
        AgentHttp::emit($res, (int)($res['http'] ?? 200));
        return;
    }
    if (!empty($res['ok'])) {
        flash_set('ok', t('core.common.saved'));
        redirect('/funds/type-map');
        return;
    }
    $error = (string)($res['error'] ?? '');
}

$data = FundsAgentOps::typeMapList();
if ($wantsJson) {
    AgentHttp::emit($data);
    return;
}

View::render('type_map', [
    'title' => t('funds.menu.type_map'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('funds.menu.list'), 'href' => '/funds'],
        ['label' => t('funds.menu.type_map'), 'current' => true],
    ]),
    'items' => $data['items'],
    'typeOptions' => $data['type_options'],
    'fundOptions' => $data['fund_options'],
    'canEdit' => $canEdit,
    'error' => $error,
], 'layout', 'funds');
