<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

if (!Auth::can('audio_phrases.edit')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => t('audio_phrases.err.forbidden')], JSON_UNESCAPED_UNICODE);
    return;
}

$id = (int)(Router::param('id') ?? 0);
try {
    $row = PhrasesRepository::setStatus($id, [
        'status' => (string)($_POST['status'] ?? ''),
        'id_dictonary' => (int)($_POST['id_dictonary'] ?? 0),
        'id_key' => (int)($_POST['id_key'] ?? 0),
        'create_key' => !empty($_POST['create_key']),
        'admin_id' => (int)($_POST['admin_id'] ?? 0),
        'force' => !empty($_POST['force']) || !empty($_POST['confirm']),
        'key_name' => (string)($_POST['key_name'] ?? ''),
    ], Auth::userId());
    if ($row === null) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => t('audio_phrases.err.not_found', ['id' => $id])], JSON_UNESCAPED_UNICODE);
        return;
    }
    echo json_encode(['ok' => true, 'item' => $row], JSON_UNESCAPED_UNICODE);
} catch (PhraseKeyOverlapException $e) {
    http_response_code(409);
    echo json_encode([
        'ok' => false,
        'needs_confirm' => true,
        'error' => $e->getMessage(),
        'overlaps' => $e->overlaps,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
