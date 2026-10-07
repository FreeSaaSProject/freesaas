<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

$path = '/audio/scripts';
$id = (int)(Router::param('id') ?? 0);

if (!Auth::can('audio_dicts.edit')) {
    if ($wantsJson) {
        AgentHttp::error(t('audio_dicts.scripts.err.forbidden'), 403);
        return;
    }
    http_response_code(403);
    View::render('error', [
        'title' => t('audio_dicts.scripts.err.forbidden'),
        'message' => t('audio_dicts.scripts.err.forbidden'),
    ], 'error', 'core');
    return;
}

try {
    $newId = RecordsScriptsRepository::copy($id);
    if ($wantsJson) {
        AgentHttp::emit(['ok' => true, 'source_id' => $id, 'id' => $newId, 'tree' => RecordsScriptsRepository::findWithParts($newId)]);
        return;
    }
    redirect($path . '/' . $newId);
} catch (Throwable $e) {
    if ($wantsJson) {
        AgentHttp::error($e->getMessage(), 400);
        return;
    }
    redirect($path . '?err=' . rawurlencode($e->getMessage()));
}
