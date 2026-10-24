<?php
declare(strict_types=1);

$id = (int)Router::param('id');
$editUrl = '/audio/dicts/' . max(0, $id) . '/edit';
if ($id < 1) {
    if (class_exists('AgentHttp', false) && AgentHttp::wantsJson()) {
        AgentHttp::error(t('audio_dicts.err.not_found', ['id' => (string)$id]), 404);
    }
    flash_set('error', t('audio_dicts.err.not_found', ['id' => (string)$id]));
    redirect('/audio/dicts');
}

$save = !empty($_POST['save']);
// overwrite is independent of save (bulk progress sends save=1 + optional overwrite).
$overwrite = !empty($_POST['overwrite']);
$res = DictDescrSuggest::suggestAndSave($id, $overwrite, $save);

if (class_exists('AgentHttp', false) && AgentHttp::wantsJson()) {
    if (!$res['ok'] && ($res['skipped'] ?? '') === '') {
        AgentHttp::error((string)$res['error'], 400);
    }
    AgentHttp::emit([
        'ok' => !empty($res['ok']),
        'id' => $id,
        'descr' => (string)$res['descr'],
        'samples' => (int)$res['samples'],
        'saved' => !empty($res['saved']),
        'skipped' => (string)($res['skipped'] ?? ''),
        'error' => (string)($res['error'] ?? ''),
    ]);
}

if (($res['skipped'] ?? '') !== '' && empty($res['ok'])) {
    flash_set('error', (string)$res['skipped']);
    redirect($editUrl);
}
if (empty($res['ok'])) {
    flash_set('error', (string)$res['error']);
    redirect($editUrl);
}

if (!empty($res['saved'])) {
    flash_set('ok', t('audio_dicts.dicts.suggest_saved', ['n' => (string)(int)$res['samples']]));
    redirect($editUrl);
}

$_SESSION['audio_dicts_descr_suggest'] = [
    'id' => $id,
    'descr' => (string)$res['descr'],
];
flash_set('ok', t('audio_dicts.dicts.suggest_ok', ['n' => (string)(int)$res['samples']]));
redirect($editUrl);
