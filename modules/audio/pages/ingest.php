<?php
declare(strict_types=1);

/**
 * Staff UI: immediate inbox → base_records ingest (oper gens/recordParser.php).
 * Token enqueue: /audio/jobs/ingest; sync: /audio/jobs/ingest/run.
 */
if (!Auth::can('audio.edit')) {
    http_response_code(403);
    echo 'Forbidden';
    return;
}
if (!ModuleManager::isEnabled('audio_pipeline')) {
    http_response_code(503);
    echo 'audio_pipeline disabled';
    return;
}

require_once base_path('modules/audio/lib/InboxFiles.php');
require_once base_path('modules/audio_pipeline/lib/JobLock.php');
require_once base_path('modules/audio_pipeline/lib/JobRunner.php');
require_once base_path('modules/audio_pipeline/lib/IngestRunner.php');

$retRaw = (string)($_GET['ret'] ?? '/audio');
$ret = NavContext::sanitize($retRaw) ?: '/audio';
$inboxPath = InboxFiles::relativeDir();

$liveMark = static function (string $text): void {
    echo '<div class="step">' . e($text) . '</div>';
    if (function_exists('ob_flush')) {
        @ob_flush();
    }
    @flush();
};

header('Content-Type: text/html; charset=utf-8');
echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>'
    . e(t('audio.inbox.ingest_title'))
    . '</title><style>
body{font:14px/1.45 system-ui,sans-serif;margin:24px;background:#111;color:#eee}
.step{margin:.35em 0;padding:.4em .6em;background:#222;border-left:3px solid #6af}
.err{color:#f88}.ok{color:#8f8} a{color:#ff8}
</style></head><body>';
echo '<h1>' . e(t('audio.inbox.ingest_title')) . '</h1>';
echo '<p class="muted">' . e(t('audio.inbox.ingest_path', ['path' => $inboxPath])) . '</p>';
echo '<div id="log">';

$pendingBefore = InboxFiles::count();
$liveMark(t('audio.inbox.ingest_pending', ['n' => (string)$pendingBefore]));

if ($pendingBefore < 1) {
    echo '</div><p class="ok">' . e(t('audio.inbox.ingest_empty')) . '</p>';
    echo '<p><a href="' . e($ret) . '">' . e(t('audio.inbox.ingest_back')) . '</a></p></body></html>';
    return;
}

$lock = JobLock::tryAcquire('ingest');
if ($lock === null) {
    echo '</div><p class="err">' . e(t('audio.inbox.ingest_busy')) . '</p>';
    echo '<p><a href="' . e($ret) . '">' . e(t('audio.inbox.ingest_back')) . '</a></p></body></html>';
    return;
}

try {
    $importBroken = !empty($_GET['importBrokenDate']);
    $result = IngestRunner::run($importBroken);
    $liveMark(t('audio.inbox.ingest_stats', [
        'processed' => (string)(int)($result['processed'] ?? 0),
        'skipped' => (string)(int)($result['skipped'] ?? 0),
        'message' => (string)($result['message'] ?? ''),
    ]));
    $notes = $result['extra']['notes'] ?? [];
    if (is_array($notes)) {
        foreach (array_slice($notes, 0, 10) as $note) {
            $liveMark((string)$note);
        }
    }
} finally {
    $lock->release();
}

$pendingAfter = InboxFiles::count();
if ($pendingAfter > 0) {
    $next = '/audio/ingest?' . http_build_query([
        'ret' => $ret,
        'importBrokenDate' => !empty($_GET['importBrokenDate']) ? '1' : '',
    ]);
    // Drop empty importBrokenDate
    $next = preg_replace('/(?:^|&)importBrokenDate=$/', '', $next) ?: $next;
    echo '</div>';
    echo '<p>' . e(t('audio.inbox.ingest_continue_hint', ['n' => (string)$pendingAfter])) . '</p>';
    echo '<p><a href="' . e($next) . '">' . e(t('audio.inbox.ingest_continue')) . '</a></p>';
    echo '<meta http-equiv="refresh" content="1;url=' . e($next) . '">';
    echo '</body></html>';
    return;
}

echo '</div>';
echo '<p class="ok"><strong>' . e(t('audio.inbox.ingest_done')) . '</strong></p>';
echo '<p><a href="' . e($ret) . '">' . e(t('audio.inbox.ingest_back')) . '</a></p>';
echo '<meta http-equiv="refresh" content="2;url=' . e($ret) . '">';
echo '</body></html>';
