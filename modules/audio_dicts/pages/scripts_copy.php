<?php
declare(strict_types=1);

$path = '/audio/scripts';
$id = (int)(Router::param('id') ?? 0);

if (!Auth::can('audio_dicts.edit')) {
    http_response_code(403);
    View::render('error', [
        'title' => t('audio_dicts.scripts.err.forbidden'),
        'message' => t('audio_dicts.scripts.err.forbidden'),
    ], 'error', 'core');
    return;
}

try {
    $newId = RecordsScriptsRepository::copy($id);
    redirect($path . '/' . $newId);
} catch (Throwable $e) {
    redirect($path . '?err=' . rawurlencode($e->getMessage()));
}
