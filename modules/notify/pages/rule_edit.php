<?php
declare(strict_types=1);

$id = (int)(Router::param('id') ?? 0);
$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$error = '';
$wantsJson = AgentHttp::wantsJson();

$rule = $id > 0 ? NotifyRulesRepository::find($id) : NotifyRuleForm::blank();
if ($rule === null) {
    if ($wantsJson) {
        AgentHttp::error(t('notify.err.rule_not_found'), 404);
    }
    http_response_code(404);
    View::render('error', [
        'title' => t('core.common.not_found'),
        'message' => t('notify.err.rule_not_found'),
    ]);
    return;
}

if ($isPost && (string)($_POST['action'] ?? 'save') === 'save') {
    $input = NotifyRuleForm::fromPost($_POST);
    try {
        $savedId = NotifyRulesRepository::save($id, $input);
        if ($wantsJson) {
            AgentHttp::emit(['ok' => true, 'id' => $savedId, 'rule' => NotifyRulesRepository::find($savedId)]);
        }
        redirect('/notify/rules');
    } catch (Throwable $e) {
        $error = $e->getMessage();
        if ($wantsJson) {
            AgentHttp::error($error, 400);
        }
        // Re-render with what the user typed.
        $rule = array_merge($rule, [
            'name' => (string)($input['name'] ?? ''),
            'event' => (string)($input['event'] ?? $rule['event']),
            'template' => (string)($input['template'] ?? ''),
            'kind' => (string)($input['kind'] ?? 'notify'),
            'enabled' => (int)$input['enabled'],
            'stop_on_match' => (int)$input['stop_on_match'],
            'sort' => (int)($input['sort'] ?? 100),
            'quiet_from' => (string)($input['quiet_from'] ?? ''),
            'quiet_to' => (string)($input['quiet_to'] ?? ''),
            'conditions' => is_array($input['conditions']) ? $input['conditions'] : [],
            'targets' => is_array($input['targets']) ? $input['targets'] : [],
            'stages' => is_array($input['stages']) ? $input['stages'] : [],
        ]);
    }
}

if ($id < 1 && (string)($rule['event'] ?? '') === '') {
    $rule['event'] = trim((string)($_GET['event'] ?? ''));
}
$def = $rule['event'] !== '' ? NotifyCatalog::get((string)$rule['event']) : null;

if ($wantsJson) {
    AgentHttp::emit(['ok' => true, 'rule' => $rule, 'event_def' => $def, 'events' => NotifyCatalog::labels()]);
}

View::render('rule_edit', [
    'title' => $id > 0 ? t('notify.rule.edit_title') : t('notify.rule.new_title'),
    'rule' => $rule,
    'def' => $def,
    'events' => NotifyCatalog::labels(),
    'error' => $error,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('notify.menu.rules'), 'href' => '/notify/rules'],
        ['label' => $id > 0 ? (string)$rule['name'] : t('notify.rules.new'), 'current' => true],
    ]),
], 'layout', 'notify');
