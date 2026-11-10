<?php
declare(strict_types=1);

/**
 * Safe purge of completed [SPEECH] fills without ask→reply DATA_TEXT.
 *
 * Skips:
 * - invites completed less than min_age_hours ago (default 12; env POLL_SPEECH_PURGE_MIN_AGE_HOURS);
 * - cabin record(s) still without TRANSCRIPTION (ASR/pipeline pending);
 * - no cabin audio in window yet.
 *
 * Before delete: tries one snippet backfill. Idempotent.
 *
 * @return array{ok:bool,message:string,deleted:int,scanned:int,backfilled:int,skipped_young:int,skipped_asr:int,skipped_other:int,min_age_hours:int}
 */
return static function (PDO $pdo, array $spec): array {
    unset($pdo, $spec);
    if (!class_exists('PollSpeechFiller', false)) {
        $path = dirname(__DIR__) . '/lib/PollSpeechFiller.php';
        if (is_file($path)) {
            require_once $path;
        }
    }
    if (!class_exists('PollSpeechFiller', false)) {
        return [
            'ok' => false,
            'message' => 'PollSpeechFiller missing',
            'deleted' => 0,
            'scanned' => 0,
            'backfilled' => 0,
            'skipped_young' => 0,
            'skipped_asr' => 0,
            'skipped_other' => 0,
            'min_age_hours' => 12,
        ];
    }
    $minAge = (int)(getenv('POLL_SPEECH_PURGE_MIN_AGE_HOURS') ?: 12);
    if ($minAge < 0) {
        $minAge = 0;
    }
    $out = PollSpeechFiller::purgeFilledWithoutSnippet([
        'min_age_hours' => $minAge,
        'try_backfill' => true,
    ]);
    return [
        'ok' => !empty($out['ok']),
        'message' => 'safe purge: backfill first; delete only when ASR ready and still no dialogue',
        'deleted' => (int)($out['deleted'] ?? 0),
        'scanned' => (int)($out['scanned'] ?? 0),
        'backfilled' => (int)($out['backfilled'] ?? 0),
        'skipped_young' => (int)($out['skipped_young'] ?? 0),
        'skipped_asr' => (int)($out['skipped_asr'] ?? 0),
        'skipped_other' => (int)($out['skipped_other'] ?? 0),
        'min_age_hours' => $minAge,
    ];
};
