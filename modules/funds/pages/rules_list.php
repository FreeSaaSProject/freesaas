<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$canEdit = Auth::can('funds.edit');
$flash = null;
$runResult = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $canEdit) {
    $input = $wantsJson ? AgentOps::requestInput() : $_POST;
    $action = (string)($input['rules_action'] ?? '');
    $res = null;
    if ($action === 'toggle') {
        $res = FundsAgentOps::rulesToggle($input);
    } elseif ($action === 'run') {
        $res = FundsAgentOps::accrue($input);
        $runResult = $res;
    } elseif ($action === 'delete') {
        $res = FundsAgentOps::rulesDelete($input);
    }
    if ($res !== null) {
        if ($wantsJson) {
            AgentHttp::emit($res, (int)($res['http'] ?? 200));
            return;
        }
        if ($action === 'run') {
            $flash = ['ok' => !empty($res['ok']), 'message' => !empty($res['ok'])
                ? t(!empty($res['dry_run']) ? 'funds.rules.run.preview' : 'funds.rules.run.done', [
                    'created' => (int)$res['created'], 'updated' => (int)$res['updated'],
                    'unchanged' => (int)$res['unchanged'], 'deleted' => (int)$res['deleted'],
                ])
                : (string)($res['error'] ?? t('funds.rules.run.failed'))];
        } else {
            flash_set(!empty($res['ok']) ? 'ok' : 'warn', !empty($res['ok']) ? t('core.common.saved') : (string)($res['error'] ?? ''));
            redirect('/funds/rules');
            return;
        }
    }
}

$items = FundRulesRepository::list();
if ($wantsJson) {
    AgentHttp::emit(['ok' => true, 'items' => $items]);
    return;
}

View::render('rules_list', [
    'title' => t('funds.menu.rules'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('funds.menu.list'), 'href' => '/funds'],
        ['label' => t('funds.menu.rules'), 'current' => true],
    ]),
    'items' => $items,
    'canEdit' => $canEdit,
    'flash' => $flash,
    'runResult' => $runResult,
    'weekAnchor' => date('Y-m-d'),
], 'layout', 'funds');
