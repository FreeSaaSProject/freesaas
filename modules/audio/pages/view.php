<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

require_once base_path('modules/audio/lib/RecordsRepository.php');
require_once base_path('modules/audio/lib/TranscriptHighlighter.php');

$id = (int)Router::param('id');
$flash = '';
$row = RecordsRepository::find($id);

if (!$row) {
    if ($wantsJson) {
        AgentHttp::error(t('audio.view.not_found', ['id' => $id]), 404);
        return;
    }
    http_response_code(404);
    View::render('error', [
        'title' => t('audio.view.not_found', ['id' => $id]),
        'message' => t('audio.view.not_found', ['id' => $id]),
    ], 'error', 'core');
    return;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && Auth::can('audio.edit')) {
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'toggle_disabled') {
        RecordsRepository::setDisabled($id, empty($row['disabled']));
        $row = RecordsRepository::find($id) ?? $row;
        if ($wantsJson) {
            AgentHttp::emit([
                'ok' => true,
                'id' => $id,
                'item' => RecordsRepository::forAgent($row, true),
            ]);
            return;
        }
        $flash = t('audio.view.saved');
    }
}

$storage = FileStorage::fromSettings();
$rel = RecordsRepository::playbackRelativePath($row);
$playUrl = $rel !== '' ? $storage->getPlaybackUrl($rel) : '';

$instances = RecordsRepository::instancesForRecords([$id]);
$trash = RecordsRepository::trashPhrases();
$transcript = TranscriptHighlighter::render(
    (string)($row['transcription'] ?? ''),
    $instances,
    $trash
);
$dictMeta = RecordsRepository::dictMetaMap();
$keyDicts = TranscriptHighlighter::parseKeyDicts(isset($row['key_dicts']) ? (string)$row['key_dicts'] : null);

if ($wantsJson) {
    $withTranscription = !empty($_GET['include_transcription']);
    AgentHttp::emit([
        'ok' => true,
        'id' => $id,
        'item' => RecordsRepository::forAgent($row, $withTranscription),
        'play_url' => $playUrl,
        'instances' => $instances,
        'key_dicts' => $keyDicts,
        'can_edit' => Auth::can('audio.edit'),
    ]);
    return;
}

View::render('view', [
    'title' => '#' . $id,
    'breadcrumbs' => Breadcrumbs::withHome([
        // href rewritten to ret=… when path matches (keeps list filters)
        ['label' => t('audio.menu.list'), 'href' => '/audio'],
        ['label' => '#' . $id, 'current' => true],
    ]),
    'row' => $row,
    'flash' => $flash,
    'playUrl' => $playUrl,
    'canEdit' => Auth::can('audio.edit'),
    'transcript' => $transcript,
    'instances' => $instances,
    'dictMeta' => $dictMeta,
    'keyDicts' => $keyDicts,
], 'layout', 'audio');
