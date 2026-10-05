<?php
declare(strict_types=1);

/**
 * JSON endpoints for list UX: add key / add trash phrase.
 * Requires audio.edit; dicts tables when audio_dicts is enabled.
 */
header('Content-Type: application/json; charset=utf-8');

if (!Auth::can('audio.edit')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => t('audio.api.forbidden')]);
    return;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => t('audio.api.method')]);
    return;
}

$action = trim((string)($_POST['action'] ?? ''));
$text = trim((string)($_POST['text'] ?? ''));
if ($text === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => t('audio.api.text_required')]);
    return;
}
$text = mb_substr($text, 0, 255);

$dictsOn = ModuleManager::isEnabled('audio_dicts');
if (!$dictsOn) {
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => t('audio.api.dicts_off')]);
    return;
}

require_once base_path('modules/audio_dicts/lib/RecordsKeysRepository.php');
require_once base_path('modules/audio_dicts/lib/RecordsTrashesRepository.php');
require_once base_path('modules/audio_dicts/lib/RecordsDictsRepository.php');
require_once base_path('modules/audio_dicts/lib/RecordsTypesRepository.php');

try {
    if ($action === 'add_key') {
        $existing = RecordsKeysRepository::findByName($text);
        if ($existing) {
            echo json_encode([
                'success' => true,
                'keyId' => (int)$existing['id'],
                'created' => false,
                'message' => t('audio.api.key_exists'),
            ]);
            return;
        }
        $typeId = max(0, (int)($_POST['type_id'] ?? 0));
        $dictId = max(0, (int)($_POST['dict_id'] ?? 0));
        if ($typeId < 1) {
            $types = RecordsTypesRepository::options();
            $typeId = $types ? (int)array_key_first($types) : 0;
        }
        if ($dictId < 1) {
            $dicts = RecordsDictsRepository::options(false);
            $dictId = $dicts ? (int)array_key_first($dicts) : 0;
        }
        $id = RecordsKeysRepository::create([
            'name' => $text,
            'disabled' => 0,
            'id_type' => $typeId,
            'id_dictonary' => $dictId,
            'id_looklike' => 0,
        ]);
        echo json_encode([
            'success' => true,
            'keyId' => $id,
            'created' => true,
            'message' => t('audio.api.key_added'),
        ]);
        return;
    }

    if ($action === 'add_trash') {
        $existing = RecordsTrashesRepository::findByName($text);
        if ($existing) {
            echo json_encode([
                'success' => true,
                'trashId' => (int)$existing['id'],
                'created' => false,
                'message' => t('audio.api.trash_exists'),
            ]);
            return;
        }
        $id = RecordsTrashesRepository::ensure($text, true);
        echo json_encode([
            'success' => true,
            'trashId' => $id,
            'created' => true,
            'message' => t('audio.api.trash_added'),
        ]);
        return;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'error' => t('audio.api.unknown_action')]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
