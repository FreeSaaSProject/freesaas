<?php
declare(strict_types=1);

/**
 * POST /audio/api/voice-sample — add manual enrollment clip from transcript selection.
 * POST check=1 — dry-run validation (duration / duplicate) without insert.
 * POST assign_speaker=1 — bind local cluster (local_id) to admin_id; optional samples=0 to skip clips.
 * GET/POST staff_list=1&record_id= — staff picker (shift on record’s day ∩ AUDIO_ANALYTICS_ENABLED).
 */
header('Content-Type: application/json; charset=utf-8');

if (!Auth::can('audio_diarize.edit')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => t('audio.api.forbidden')]);
    return;
}

require_once base_path('modules/audio_diarize/lib/SpeakerSamplesRepository.php');
require_once base_path('modules/audio_diarize/lib/SpeakerService.php');

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
$assignSpeaker = !empty($_POST['assign_speaker']);

// Do NOT silently fall back to badge wearer — that routed clips to the wrong staff (e.g. ЭТА speech → ДВМ).
// Client must send admin_id from the staff picker.

$map = [
    'samples_unavailable' => t('audio_diarize.voice_sample.err.unavailable'),
    'speakers_unavailable' => t('audio_diarize.voice_sample.err.unavailable'),
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
    'no_clips' => t('audio_diarize.speaker_assign.err.no_clips'),
    'speaker_missing' => t('audio_diarize.speaker_assign.err.missing'),
];

if ($assignSpeaker) {
    $localId = trim((string)($_POST['local_id'] ?? ''));
    $addSamples = !isset($_POST['samples']) || (string)$_POST['samples'] !== '0';
    $excludeRanges = SpeakerService::parseExcludeRanges($_POST['exclude_json'] ?? []);
    $includeRanges = SpeakerService::parseExcludeRanges($_POST['include_json'] ?? []);
    $res = SpeakerService::assignLocalToAdmin(
        $recordId,
        $localId,
        $adminId,
        $addSamples,
        $excludeRanges,
        $includeRanges
    );
    if (!$res['ok']) {
        echo json_encode([
            'success' => false,
            'error' => $map[$res['error']] ?? t('audio_diarize.speaker_assign.err.generic'),
            'error_code' => $res['error'],
        ], JSON_UNESCAPED_UNICODE);
        return;
    }
    $samples = is_array($res['samples'] ?? null) ? $res['samples'] : null;
    $adminName = '';
    $adminAcr = '';
    if ($adminId > 0 && class_exists('UsersRepository', false)) {
        $adminName = trim((string)UsersRepository::nameById($adminId));
        $adminAcr = UsersRepository::shortName($adminName);
    }
    $who = $adminAcr !== '' ? $adminAcr : ($adminName !== '' ? $adminName : ('#' . $adminId));
    $added = (int)($samples['added'] ?? 0);
    $replaced = (int)($samples['replaced'] ?? 0);
    $msg = t('audio_diarize.speaker_assign.ok', [
        'local' => $localId,
        'who' => $who,
        'n' => (string)$added,
    ]);
    if ($replaced > 0) {
        $msg = t('audio_diarize.speaker_assign.ok_rebuild', [
            'local' => $localId,
            'who' => $who,
            'n' => (string)$added,
            'old' => (string)$replaced,
        ]);
    }
    echo json_encode([
        'success' => true,
        'local_id' => $localId,
        'admin_id' => $adminId,
        'admin_name' => $adminName,
        'admin_acronym' => $adminAcr,
        'message' => $msg,
        'samples' => $samples,
        'adopted_cues' => (int)($res['adopted_cues'] ?? 0),
        'recommended' => SpeakerSamplesRepository::RECOMMENDED_MANUAL,
        'max_manual' => SpeakerSamplesRepository::MAX_MANUAL,
        'manual_count' => (int)($samples['manual_count'] ?? 0),
        'remaining' => (int)($samples['remaining'] ?? 0),
    ], JSON_UNESCAPED_UNICODE);
    return;
}

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

$adminName = '';
$adminAcr = '';
if ($adminId > 0 && class_exists('UsersRepository', false)) {
    $adminName = trim((string)UsersRepository::nameById($adminId));
    $adminAcr = UsersRepository::shortName($adminName);
}
$who = $adminAcr !== '' ? $adminAcr : ($adminName !== '' ? $adminName : ('#' . $adminId));
$msgKey = $res['manual_count'] > SpeakerSamplesRepository::RECOMMENDED_MANUAL
    ? 'audio_diarize.voice_sample.added_extra'
    : 'audio_diarize.voice_sample.added';
echo json_encode([
    'success' => true,
    'id' => $res['id'],
    'admin_id' => $adminId,
    'admin_name' => $adminName,
    'admin_acronym' => $adminAcr,
    'message' => t($msgKey, [
        'who' => $who,
        'n' => (string)$res['manual_count'],
        'remain' => (string)$res['remaining'],
        'rec' => (string)SpeakerSamplesRepository::RECOMMENDED_MANUAL,
    ]),
    'manual_count' => $res['manual_count'],
    'remaining' => $res['remaining'],
    'duration' => $res['duration'] ?? ($t1 - $t0),
] + $meta, JSON_UNESCAPED_UNICODE);
