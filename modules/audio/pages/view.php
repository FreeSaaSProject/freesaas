<?php
declare(strict_types=1);

require_once base_path('modules/audio/lib/RecordsRepository.php');
require_once base_path('modules/audio/lib/TranscriptHighlighter.php');

$id = (int)Router::param('id');
$flash = '';
$row = RecordsRepository::find($id);

if (!$row) {
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
$typeMeta = RecordsRepository::typeMetaMap();
$keyTypes = TranscriptHighlighter::parseKeyTypes(isset($row['key_types']) ? (string)$row['key_types'] : null);

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
    'typeMeta' => $typeMeta,
    'keyTypes' => $keyTypes,
], 'layout', 'audio');
