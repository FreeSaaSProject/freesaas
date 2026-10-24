<?php
declare(strict_types=1);

/**
 * JSON endpoints for list UX: add key / add trash phrase.
 * Requires audio.edit; dicts tables when audio_dicts is enabled.
 * After add_key with record_id: optional single-record stem+collect (SCORE refresh).
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
$force = !empty($_POST['force']) || !empty($_POST['confirm']);
$recordId = max(0, (int)($_POST['record_id'] ?? 0));

$dictsOn = ModuleManager::isEnabled('audio_dicts');
if (!$dictsOn) {
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => t('audio.api.dicts_off')]);
    return;
}

require_once base_path('modules/audio_dicts/lib/RecordsKeysRepository.php');
require_once base_path('modules/audio_dicts/lib/RecordsTrashesRepository.php');
require_once base_path('modules/audio_dicts/lib/RecordsDictsRepository.php');

/**
 * @param list<array{id:int,name:string,id_dictonary:int,dict_name:string,relation:string}> $hits
 * @return list<array<string,mixed>>
 */
$labelOverlaps = static function (array $hits): array {
    $labels = [
        'exact' => t('audio.api.overlap_exact'),
        'contains' => t('audio.api.overlap_contains'),
        'contained' => t('audio.api.overlap_contained'),
    ];
    $out = [];
    foreach ($hits as $h) {
        $rel = (string)($h['relation'] ?? '');
        $h['relation_label'] = $labels[$rel] ?? $rel;
        $out[] = $h;
    }
    return $out;
};

/**
 * @return array{ok:bool,score?:int,message?:string}|null
 */
$reparseRecordIfPossible = static function (int $rid): ?array {
    if ($rid < 1 || !ModuleManager::isEnabled('audio_pipeline')) {
        return null;
    }
    $stemLib = base_path('modules/audio_pipeline/lib/StemRunner.php');
    if (!is_file($stemLib)) {
        return null;
    }
    require_once base_path('modules/audio_pipeline/lib/JobRunner.php');
    require_once $stemLib;
    if (!class_exists('StemRunner', false)) {
        return null;
    }
    try {
        $out = StemRunner::reparseRecord($rid);

        return [
            'ok' => !empty($out['ok']),
            'score' => (int)($out['score'] ?? 0),
            'message' => (string)($out['message'] ?? ''),
        ];
    } catch (Throwable $e) {
        return [
            'ok' => false,
            'message' => $e->getMessage(),
        ];
    }
};

try {
    if ($action === 'add_key') {
        $existing = RecordsKeysRepository::findByName($text);
        if ($existing) {
            $keyId = (int)$existing['id'];
            $dictId = (int)($existing['id_dictonary'] ?? 0);
            $dictName = '';
            if ($dictId > 0) {
                $dictRow = RecordsDictsRepository::find($dictId);
                if (is_array($dictRow)) {
                    $dictName = (string)($dictRow['name'] ?? '');
                }
            }
            echo json_encode([
                'success' => true,
                'keyId' => $keyId,
                'created' => false,
                'already_exists' => true,
                'message' => t('audio.api.key_exists'),
                'key' => [
                    'id' => $keyId,
                    'name' => (string)($existing['name'] ?? $text),
                    'disabled' => (int)($existing['disabled'] ?? 0) > 0,
                    'dict_id' => $dictId,
                    'dict_name' => $dictName,
                    'edit_url' => '/audio/keys/' . $keyId . '/edit',
                    'dict_edit_url' => $dictId > 0 ? '/audio/dicts/' . $dictId . '/edit' : '',
                ],
            ]);
            return;
        }

        $overlaps = RecordsKeysRepository::findOverlaps($text);
        if ($overlaps !== [] && !$force) {
            echo json_encode([
                'success' => false,
                'needs_confirm' => true,
                'overlaps' => $labelOverlaps($overlaps),
                'error' => t('audio.api.key_overlaps'),
                'message' => t('audio.api.key_overlaps'),
            ]);
            return;
        }

        $dictId = max(0, (int)($_POST['dict_id'] ?? 0));
        $adminId = max(0, (int)($_POST['admin_id'] ?? 0));
        $dicts = $adminId > 0
            ? RecordsDictsRepository::optionsForAdmin($adminId, false)
            : RecordsDictsRepository::options(false);
        if ($dictId < 1) {
            $dictId = $dicts ? (int)array_key_first($dicts) : 0;
        }
        if ($dictId < 1) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => t('audio.api.dict_required')]);
            return;
        }
        if ($adminId > 0 && !RecordsDictsRepository::isDictAllowedForAdmin($dictId, $adminId)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => t('audio.api.dict_not_for_position')]);
            return;
        }
        if ($adminId < 1 && !isset($dicts[$dictId])) {
            $all = RecordsDictsRepository::options(false);
            if (!isset($all[$dictId])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => t('audio.api.dict_required')]);
                return;
            }
        }
        $looklike = 0;
        if ($overlaps !== []) {
            $looklike = (int)$overlaps[0]['id'];
        }
        $id = RecordsKeysRepository::create([
            'name' => $text,
            'disabled' => 0,
            'id_dictonary' => $dictId,
            'id_looklike' => $looklike,
        ]);
        $payload = [
            'success' => true,
            'keyId' => $id,
            'created' => true,
            'message' => t('audio.api.key_added'),
            'overlaps' => $labelOverlaps($overlaps),
            'id_looklike' => $looklike,
        ];
        $re = $reparseRecordIfPossible($recordId);
        if ($re !== null) {
            $payload['reparsed'] = $re;
            if (!empty($re['ok'])) {
                $payload['message'] = t('audio.api.key_added_reparsed', [
                    'score' => (string)(int)($re['score'] ?? 0),
                ]);
            } else {
                $payload['message'] = t('audio.api.key_added_reparse_failed');
            }
        }
        echo json_encode($payload);
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
