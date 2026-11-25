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
    if ($action === 'backfill') {
        $res = FundsAgentOps::backfill($input);
    } elseif ($action === 'delete') {
        $res = FundsAgentOps::typeMapDelete($input);
    } else {
        $res = FundsAgentOps::typeMapSave($input);
    }
    if ($wantsJson) {
        AgentHttp::emit($res, (int)($res['http'] ?? 200));
        return;
    }
    if (!empty($res['ok'])) {
        $message = $action === 'backfill'
            ? t(!empty($res['dry_run']) ? 'funds.backfill.preview' : 'funds.backfill.done', [
                'n' => (int)($res['created'] ?? 0),
                'summ' => FundMovementsRepository::fmt((float)($res['summ'] ?? 0)),
            ])
            : t('core.common.saved');
        flash_set('ok', $message);
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
