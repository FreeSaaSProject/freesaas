<?php
declare(strict_types=1);

$id = (int)(Router::param('id') ?? 0);
$rule = NotifyRulesRepository::find($id);
if ($rule === null) {
    if (AgentHttp::wantsJson()) {
        AgentHttp::error(t('notify.err.rule_not_found'), 404);
    }
    http_response_code(404);
    View::render('error', [
        'title' => t('core.common.not_found'),
        'message' => t('notify.err.rule_not_found'),
    ]);
    return;
}

$def = NotifyCatalog::get($rule['event']) ?? [];
$result = null;
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $ctx = [];
    foreach ((array)($def['vars'] ?? []) as $v) {
        $ctx[(string)$v] = trim((string)($_POST['ctx'][$v] ?? '[' . $v . ']'));
    }
    foreach ((array)($_POST['cond'] ?? []) as $k => $v) {
        $ctx[(string)$k] = is_array($v) ? $v : trim((string)$v);
    }
    $subjects = [];
    if ((int)($_POST['client_id'] ?? 0) > 0) {
        $subjects['client'] = ['client_id' => (int)$_POST['client_id']];
    }
    if ((int)($_POST['user_id'] ?? 0) > 0) {
        $subjects['operator'] = ['user_id' => (int)$_POST['user_id']];
    }
    $result = NotifyEngine::testRule($rule, [
        'ctx' => $ctx !== [] ? $ctx : NotifyEngine::sampleCtx($def),
        'subjects' => $subjects,
        'entity_id' => 0,
    ]);
}

if (AgentHttp::wantsJson()) {
    AgentHttp::emit(['ok' => true, 'rule' => $rule, 'result' => $result, 'def' => $def]);
}

View::render('rule_test', [
    'title' => t('notify.rule.test_title'),
    'rule' => $rule,
    'def' => $def,
    'result' => $result,
    'error' => $error,
    'sample' => NotifyEngine::sampleCtx($def),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('notify.menu.rules'), 'href' => '/notify/rules'],
        ['label' => (string)$rule['name'], 'href' => '/notify/rules/' . $id . '/edit'],
        ['label' => t('notify.rules.test'), 'current' => true],
    ]),
], 'layout', 'notify');
