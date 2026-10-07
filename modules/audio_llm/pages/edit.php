<?php
declare(strict_types=1);

$path = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '');
$isNew = (bool)preg_match('#/audio/llm-studies/new/?$#', $path);
if (!$isNew) {
    redirect('/audio/llm-studies');
}

$error = '';
$copyFrom = (int)($_GET['cfrom'] ?? 0);
$copy = $copyFrom > 0 ? StudiesRepository::find($copyFrom) : null;

$defaults = [
    'title' => $copy ? (string)$copy['TITLE'] : '',
    'prompt' => $copy ? (string)$copy['PROMPT'] : '',
    'is_cyclic' => $copy && !empty($copy['IS_CYCLIC']),
    'cyclic_enabled' => !$copy || !empty($copy['CYCLIC_ENABLED']),
    'trigger_good_id' => $copy ? (int)$copy['TRIGGER_GOOD_ID'] : 0,
    'amount_positive' => $copy ? (int)$copy['AMOUNT_POSITIVE'] : 0,
    'amount_negative' => $copy ? (int)$copy['AMOUNT_NEGATIVE'] : -150,
    'selection_mode' => 'admin_range',
    'id_admin' => $copy ? (int)$copy['ID_ADMIN'] : 0,
    'dt_start' => date('d.m.Y') . ' 00:00',
    'dt_end' => date('d.m.Y') . ' 23:59',
    'record_ids_raw' => '',
];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $defaults['title'] = trim((string)($_POST['title'] ?? ''));
    $defaults['prompt'] = trim((string)($_POST['prompt'] ?? ''));
    $defaults['is_cyclic'] = !empty($_POST['is_cyclic']);
    $defaults['cyclic_enabled'] = !empty($_POST['cyclic_enabled']);
    $defaults['trigger_good_id'] = (int)($_POST['trigger_good_id'] ?? 0);
    $defaults['amount_positive'] = (int)($_POST['amount_positive'] ?? 0);
    $defaults['amount_negative'] = (int)($_POST['amount_negative'] ?? 0);
    $defaults['selection_mode'] = (string)($_POST['selection_mode'] ?? 'record_ids');
    $defaults['id_admin'] = (int)($_POST['id_admin'] ?? 0);
    $defaults['dt_start'] = (string)($_POST['dt_start'] ?? '');
    $defaults['dt_end'] = (string)($_POST['dt_end'] ?? '');
    $defaults['record_ids_raw'] = trim((string)($_POST['record_ids_raw'] ?? ''));
    $createdBy = Auth::userId();

    if ($defaults['is_cyclic']) {
        $res = StudiesRepository::createCyclic(
            $defaults['title'],
            $defaults['prompt'],
            $defaults['trigger_good_id'],
            $defaults['amount_positive'],
            $defaults['amount_negative'],
            $defaults['cyclic_enabled'],
            $createdBy
        );
    } else {
        $mode = $defaults['selection_mode'] === 'admin_range' ? 'admin_range' : 'record_ids';
        $params = [];
        if ($mode === 'admin_range') {
            $params['id_admin'] = $defaults['id_admin'];
            $params['dt_range_start'] = StudiesRepository::parseDatetime($defaults['dt_start']);
            $params['dt_range_end'] = StudiesRepository::parseDatetime($defaults['dt_end']);
        } else {
            $params['record_ids_raw'] = $defaults['record_ids_raw'];
        }
        $res = StudiesRepository::create(
            $defaults['title'],
            $mode,
            $params,
            $defaults['prompt'],
            $createdBy
        );
    }

    if (!empty($res['ok'])) {
        if (AgentHttp::wantsJson()) {
            AgentHttp::emit(['ok' => true, 'id' => $res['id'], 'total' => $res['total'] ?? 0]);
        }
        redirect('/audio/llm-studies/' . (int)$res['id'] . '?saved=1');
    }
    $error = (string)($res['error'] ?? t('audio_llm.err.prompt'));
    if (AgentHttp::wantsJson()) {
        AgentHttp::error($error);
    }
}

$employees = [];
if (Database::tableExists('base_users')) {
    $st = Database::pdo()->query(
        'SELECT id, name FROM base_users WHERE enabled = 1 ORDER BY name ASC LIMIT 2000'
    );
    if ($st) {
        $employees = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

View::render('edit', [
    'title' => t('audio_llm.new.title'),
    'error' => $error,
    'form' => $defaults,
    'employees' => $employees,
    'copy' => $copy,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('audio_llm.menu.studies'), 'href' => '/audio/llm-studies'],
        ['label' => t('audio_llm.new.title'), 'current' => true],
    ]),
], 'layout', 'audio_llm');
