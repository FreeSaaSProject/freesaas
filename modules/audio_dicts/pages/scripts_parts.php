<?php
declare(strict_types=1);

/**
 * JSON API for canvas part ops: reorder / save / delete / add.
 */
header('Content-Type: application/json; charset=utf-8');

if (!Auth::can('audio_dicts.edit')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => t('audio_dicts.scripts.err.forbidden')], JSON_UNESCAPED_UNICODE);
    return;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => t('audio_dicts.scripts.err.method')], JSON_UNESCAPED_UNICODE);
    return;
}

$scriptId = (int)(Router::param('id') ?? 0);
$tree = RecordsScriptsRepository::findWithParts($scriptId);
if ($tree === null) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => t('audio_dicts.scripts.err.not_found', ['id' => $scriptId])], JSON_UNESCAPED_UNICODE);
    return;
}

$action = trim((string)($_POST['action'] ?? ''));
$body = $_POST;
if (empty($body['parts']) && !empty($body['parts_json'])) {
    // keep for partsFromPost
}

try {
    if ($action === 'reorder') {
        $order = $_POST['order'] ?? [];
        if (is_string($order)) {
            $decoded = json_decode($order, true);
            $order = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($order) || $order === []) {
            throw new InvalidArgumentException(t('audio_dicts.scripts.err.reorder'));
        }
        RecordsScriptsRepository::reorderParts($scriptId, array_map('intval', $order));
        $fresh = RecordsScriptsRepository::findWithParts($scriptId);
        echo json_encode(['ok' => true, 'parts' => $fresh['parts'] ?? []], JSON_UNESCAPED_UNICODE);
        return;
    }

    if ($action === 'delete') {
        $partId = (int)($_POST['part_id'] ?? 0);
        if ($partId < 1) {
            throw new InvalidArgumentException(t('audio_dicts.scripts.err.part_not_found', ['id' => $partId]));
        }
        RecordsScriptsRepository::deletePart($scriptId, $partId);
        $fresh = RecordsScriptsRepository::findWithParts($scriptId);
        echo json_encode(['ok' => true, 'parts' => $fresh['parts'] ?? []], JSON_UNESCAPED_UNICODE);
        return;
    }

    if ($action === 'save' || $action === 'add') {
        $partData = [
            'id' => (int)($_POST['part_id'] ?? $_POST['id'] ?? 0),
            'name' => trim((string)($_POST['name'] ?? '')),
            'roles' => [],
            'score' => (int)($_POST['score'] ?? 0),
            'timeout' => (int)($_POST['timeout'] ?? 0),
            'diversity' => (int)($_POST['diversity'] ?? 0),
            'instances_min' => (int)($_POST['instances_min'] ?? 0),
            'disabled' => !empty($_POST['disabled']) ? 1 : 0,
            'dict_ids' => [],
        ];
        $rolesRaw = $_POST['roles'] ?? null;
        if (is_string($rolesRaw)) {
            $decoded = json_decode($rolesRaw, true);
            $rolesRaw = is_array($decoded) ? $decoded : explode(',', $rolesRaw);
        }
        if (is_array($rolesRaw)) {
            $partData['roles'] = $rolesRaw;
        } elseif (!empty($_POST['role'])) {
            $partData['roles'] = [(string)$_POST['role']];
        } else {
            $partData['roles'] = ['ordinary'];
        }
        $dicts = $_POST['dict_ids'] ?? [];
        if (is_string($dicts)) {
            $decoded = json_decode($dicts, true);
            $dicts = is_array($decoded) ? $decoded : RecordsScriptsRepository::parseIds($dicts);
        }
        if (is_array($dicts)) {
            $partData['dict_ids'] = array_values(array_filter(array_map('intval', $dicts)));
        }
        if ($partData['name'] === '') {
            throw new InvalidArgumentException(t('audio_dicts.err.name_required'));
        }
        if ($action === 'add') {
            $partData['id'] = 0;
        }
        $part = RecordsScriptsRepository::savePart($scriptId, $partData);
        $fresh = RecordsScriptsRepository::findWithParts($scriptId);
        echo json_encode(['ok' => true, 'part' => $part, 'parts' => $fresh['parts'] ?? []], JSON_UNESCAPED_UNICODE);
        return;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => t('audio_dicts.scripts.err.unknown_action')], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
