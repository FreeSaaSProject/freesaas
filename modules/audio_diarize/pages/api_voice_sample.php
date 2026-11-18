<?php
declare(strict_types=1);

/**
 * POST /audio/api/voice-sample — add manual enrollment clip from transcript selection.
 * POST check=1 — dry-run validation (duration / duplicate) without insert.
 * GET/POST staff_list=1&record_id= — staff picker (on shift today, audio analytics, no assist/hidden).
 */
header('Content-Type: application/json; charset=utf-8');

if (!Auth::can('audio_diarize.edit')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => t('audio.api.forbidden')]);
    return;
}

require_once base_path('modules/audio_diarize/lib/SpeakerSamplesRepository.php');

$staffList = !empty($_REQUEST['staff_list']);
if ($staffList) {
    $recordId = (int)($_REQUEST['record_id'] ?? 0);
    $opts = SpeakerSamplesRepository::staffOptionsForRecord($recordId);
    echo json_encode([
        'success' => true,
        'staff' => $opts,
        'recommended' => SpeakerSamplesRepository::RECOMMENDED_MANUAL,
        'max_manual' => SpeakerSamplesRepository::MAX_MANUAL,
    ], JSON_UNESCAPED_UNICODE);
    return;
}

$recordId = (int)($_POST['record_id'] ?? 0);
$adminId = (int)($_POST['admin_id'] ?? 0);
$t0 = (float)($_POST['t0'] ?? 0);
$t1 = (float)($_POST['t1'] ?? 0);
$checkOnly = !empty($_POST['check']);

if ($adminId < 1 && $recordId > 0 && Database::tableExists('base_records')) {
    $st = Database::pdo()->prepare('SELECT ID_ADMIN FROM base_records WHERE ID = ? LIMIT 1');
    $st->execute([$recordId]);
    $adminId = (int)($st->fetchColumn() ?: 0);
}

$map = [
    'samples_unavailable' => t('audio_diarize.voice_sample.err.unavailable'),
    'bad_args' => t('audio_diarize.voice_sample.err.args'),
    'bad_range' => t('audio_diarize.voice_sample.err.range'),
    'too_short' => t('audio_diarize.voice_sample.err.short', [
        'min' => (string)(int)SpeakerSamplesRepository::MIN_CLIP_SEC,
    ]),
    'too_long' => t('audio_diarize.voice_sample.err.long', [
        'max' => (string)(int)SpeakerSamplesRepository::MAX_CLIP_SEC,
    ]),
    'too_many' => t('audio_diarize.voice_sample.err.too_many', [
        'max' => (string)SpeakerSamplesRepository::MAX_MANUAL,
    ]),
    'bad_admin' => t('audio_diarize.voice_sample.err.bad_admin'),
    'admin_mismatch' => t('audio_diarize.voice_sample.err.admin'),
    'duplicate' => t('audio_diarize.voice_sample.err.duplicate'),
];

$meta = [
    'recommended' => SpeakerSamplesRepository::RECOMMENDED_MANUAL,
    'max_manual' => SpeakerSamplesRepository::MAX_MANUAL,
    'min_sec' => SpeakerSamplesRepository::MIN_CLIP_SEC,
    'max_sec' => SpeakerSamplesRepository::MAX_CLIP_SEC,
];

if ($checkOnly) {
    $res = SpeakerSamplesRepository::validateManual($adminId, $recordId, $t0, $t1);
    if (!$res['ok']) {
        echo json_encode([
            'success' => false,
            'ok' => false,
            'error' => $map[$res['error']] ?? t('audio_diarize.voice_sample.err.generic'),
            'error_code' => $res['error'],
            'manual_count' => $res['manual_count'],
            'remaining' => $res['remaining'],
            'duration' => $res['duration'],
            'overlap_id' => $res['overlap_id'],
        ] + $meta, JSON_UNESCAPED_UNICODE);
        return;
    }
    echo json_encode([
        'success' => true,
        'ok' => true,
        'manual_count' => $res['manual_count'],
        'remaining' => $res['remaining'],
        'duration' => $res['duration'],
    ] + $meta, JSON_UNESCAPED_UNICODE);
    return;
}

$res = SpeakerSamplesRepository::addManual($adminId, $recordId, $t0, $t1);
if (!$res['ok']) {
    $err = $map[$res['error']] ?? t('audio_diarize.voice_sample.err.generic');
    echo json_encode([
        'success' => false,
        'error' => $err,
        'error_code' => $res['error'],
        'manual_count' => $res['manual_count'],
        'remaining' => $res['remaining'],
        'duration' => $res['duration'] ?? max(0.0, $t1 - $t0),
        'overlap_id' => $res['overlap_id'] ?? 0,
    ] + $meta, JSON_UNESCAPED_UNICODE);
    return;
}

if (class_exists('DiarizePushDispatcher', false)) {
    DiarizePushDispatcher::afterQueueChange();
}

$msgKey = $res['manual_count'] > SpeakerSamplesRepository::RECOMMENDED_MANUAL
    ? 'audio_diarize.voice_sample.added_extra'
    : 'audio_diarize.voice_sample.added';
echo json_encode([
    'success' => true,
    'id' => $res['id'],
    'message' => t($msgKey, [
        'n' => (string)$res['manual_count'],
        'remain' => (string)$res['remaining'],
        'rec' => (string)SpeakerSamplesRepository::RECOMMENDED_MANUAL,
    ]),
    'manual_count' => $res['manual_count'],
    'remaining' => $res['remaining'],
    'duration' => $res['duration'] ?? ($t1 - $t0),
] + $meta, JSON_UNESCAPED_UNICODE);
