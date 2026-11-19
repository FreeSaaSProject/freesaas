<?php
declare(strict_types=1);

/**
 * POST /audio/asr-dia/longjob — LongJob start/tick/cancel for asr_dia experiment.
 */
require_once base_path('modules/audio_diarize/lib/AudioAsrDiaLongJob.php');

$action = (string)($_POST['action'] ?? '');
if ($action === 'job_start') {
    $opts = [
        'day' => (string)($_POST['day'] ?? ''),
        'ids_csv' => (string)($_POST['ids_csv'] ?? ''),
        'asr_model' => (string)($_POST['asr_model'] ?? ''),
        'apply_speakers' => !isset($_POST['apply_speakers']) || !empty($_POST['apply_speakers']),
        'apply_transcription' => !empty($_POST['apply_transcription']),
        'ret' => (string)($_POST['ret'] ?? '/audio/asr-dia'),
    ];
    if (!empty($_POST['record_ids']) && is_array($_POST['record_ids'])) {
        $opts['record_ids'] = array_map('intval', $_POST['record_ids']);
    }
    LongJob::httpStart(AudioAsrDiaLongJob::TYPE, $opts);
    return;
}

LongJob::httpTickOrCancel(AudioAsrDiaLongJob::TYPE);
