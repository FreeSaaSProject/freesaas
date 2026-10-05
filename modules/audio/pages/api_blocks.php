<?php
declare(strict_types=1);

/**
 * Lazy transcript blocks for the records list.
 * GET /audio/api/blocks?ids=1,2,3&search=…
 */
header('Content-Type: application/json; charset=utf-8');

if (!Auth::can('audio.view')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => t('audio.api.forbidden')]);
    return;
}

$rawIds = (string)($_GET['ids'] ?? '');
$search = trim((string)($_GET['search'] ?? ''));
$ids = [];
foreach (preg_split('/[,\s]+/', $rawIds) ?: [] as $part) {
    $id = (int)$part;
    if ($id > 0) {
        $ids[$id] = $id;
    }
}
$ids = array_values($ids);
if (count($ids) > 24) {
    $ids = array_slice($ids, 0, 24);
}
if (!$ids) {
    echo json_encode(['success' => true, 'blocks' => new stdClass()]);
    return;
}

require_once base_path('modules/audio/lib/RecordsRepository.php');
require_once base_path('modules/audio/lib/TranscriptHighlighter.php');

$rows = RecordsRepository::findByIds($ids);
$instances = RecordsRepository::instancesForRecords(array_keys($rows));
$byRec = [];
foreach ($instances as $inst) {
    $byRec[(int)$inst['id_record']][] = $inst;
}
$trash = RecordsRepository::trashPhrases();
$candidates = [];
$blocks = [];

foreach ($ids as $rid) {
    $row = $rows[$rid] ?? null;
    if (!$row) {
        $blocks[(string)$rid] = [
            'html' => '<p class="muted">' . te('audio.view.not_found', ['id' => $rid]) . '</p>',
            'lines' => 0,
            'trash' => 0,
            'keys' => 0,
            'play_url' => '',
            'download_url' => '',
        ];
        continue;
    }
    $rendered = TranscriptHighlighter::render(
        (string)($row['transcription'] ?? ''),
        $byRec[$rid] ?? [],
        $trash,
        $search
    );
    foreach ($rendered['trash_candidates'] ?? [] as $cand) {
        $candidates[$cand] = $cand;
    }
    $playUrl = '';
    $dlUrl = '';
    $rel = RecordsRepository::playbackRelativePath($row);
    if ($rel !== '') {
        $qs = 'file=' . rawurlencode($rel);
        $playUrl = '/audio/play?' . $qs;
        $dlUrl = '/audio/play?' . $qs . '&download=1';
    }
    $blocks[(string)$rid] = [
        'html' => $rendered['html'],
        'lines' => (int)$rendered['lines'],
        'trash' => (int)$rendered['trash'],
        'keys' => (int)$rendered['keys'],
        'play_url' => $playUrl,
        'download_url' => $dlUrl,
    ];
}

$trashAdded = 0;
if ($candidates && ModuleManager::isEnabled('audio_dicts') && Auth::can('audio.edit')) {
    require_once base_path('modules/audio_dicts/lib/RecordsTrashesRepository.php');
    $trashAdded = RecordsTrashesRepository::ensureMany(array_values($candidates));
}

echo json_encode([
    'success' => true,
    'blocks' => $blocks,
    'trash_added' => $trashAdded,
], JSON_UNESCAPED_UNICODE);
