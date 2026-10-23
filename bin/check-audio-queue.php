#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * AudioJobQueue stem RMW: concurrent enqueue must not be wiped by head drop.
 * Late ASR result defers the record's own past day (no postpone on repeat); morning sweep queues yesterday now.
 * Staff report comment change clears ticket read marks.
 * Exit: 0 ok, 1 fail; SKIP if audio_pipeline disabled.
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';

$failed = 0;
$assert = static function (bool $ok, string $msg) use (&$failed): void {
    echo ($ok ? 'OK  ' : 'FAIL ') . $msg . "\n";
    if (!$ok) {
        $failed++;
    }
};

if (!ModuleManager::isEnabled('audio_pipeline') || !class_exists('AudioJobQueue', false)) {
    echo "SKIP audio_pipeline disabled\n";
    exit(0);
}

// Keep a live instance's queue intact across the check.
$queueFile = base_path('var/audio_jobs/queue_stem.json');
$queueBackup = is_file($queueFile) ? (string)file_get_contents($queueFile) : null;

$ref = new ReflectionClass(AudioJobQueue::class);
$priv = static function (string $name) use ($ref): ReflectionMethod {
    $m = $ref->getMethod($name);
    $m->setAccessible(true);
    return $m;
};
$drop = $priv('dropStemJobByKey');
$withLock = $priv('withStemQueueLock');
$read = $priv('read');
$fromPayload = $priv('stemJobsFromPayload');
$persist = $priv('persistStemJobs');
$keyFn = $priv('stemJobKey');
$fromOpts = $priv('stemJobFromOpts');
$sweepFn = $priv('enqueueNightlySweep');
$dueFn = $priv('firstDueStemJob');
$jobFor = static function (string $day) use ($fromPayload, $read): ?array {
    foreach ($fromPayload->invoke(null, $read->invoke(null, 'stem')) as $j) {
        if ((string)$j['day'] === $day) {
            return $j;
        }
    }
    return null;
};
$queuedDays = static function () use ($fromPayload, $read): array {
    return array_map(
        static fn (array $j): string => (string)$j['day'],
        $fromPayload->invoke(null, $read->invoke(null, 'stem'))
    );
};

try {
    AudioJobQueue::clear('stem');
    $r1 = AudioJobQueue::requestStem(['day' => '2099-01-01', 'admin' => 1, 'collect' => false, 'publish' => false]);
    $assert(!empty($r1['ok']), 'enqueue day A');
    $r2 = AudioJobQueue::requestStem(['day' => '2099-01-02', 'admin' => 2, 'collect' => false, 'publish' => false]);
    $assert(!empty($r2['ok']), 'enqueue day B');
    $assert((int)($r2['jobs'] ?? 0) === 2, 'two scopes queued');

    $headKey = $keyFn->invoke(null, $fromOpts->invoke(null, ['day' => '2099-01-01', 'admin' => 1]));
    $remaining = $withLock->invoke(null, static function () use ($drop, $read, $fromPayload, $persist, $headKey): int {
        $fresh = $fromPayload->invoke(null, $read->invoke(null, 'stem'));
        $fresh = $drop->invoke(null, $fresh, $headKey);
        $persist->invoke(null, $fresh);
        return count($fresh);
    });
    $assert($remaining === 1, 'drop head keeps sibling (remaining=1)');

    $after = $fromPayload->invoke(null, $read->invoke(null, 'stem'));
    $assert(count($after) === 1, 'disk has one job');
    $assert((string)($after[0]['day'] ?? '') === '2099-01-02', 'sibling day B survives');

    // ASR result → stem for the record's own day (within lookback only).
    if (class_exists('AsrService', false) && class_exists('StemRunner', false)) {
        $asrRef = new ReflectionClass(AsrService::class);
        $enqueueDay = $asrRef->getMethod('enqueueStemForRecordDay');
        $enqueueDay->setAccessible(true);

        AudioJobQueue::clear('stem');
        $threeDaysAgo = (int)mktime(14, 30, 0, (int)date('n'), (int)date('j') - 3, (int)date('Y'));
        $lateDay = date('Y-m-d', $threeDaysAgo);
        $t0 = time();
        $day = (string)$enqueueDay->invoke(null, $threeDaysAgo);
        $assert($day === $lateDay, 'late record enqueues its own day');
        $job = $jobFor($lateDay);
        $nb1 = $job !== null ? (int)$job['not_before'] : 0;
        $assert($nb1 >= $t0 + AsrService::lateStemDelaySec() - 2, 'late day is deferred by late_stem_delay_sec');

        $assert($dueFn->invoke(null, $fromPayload->invoke(null, $read->invoke(null, 'stem')), time()) === null, 'deferred job is not due yet');
        $dueLater = $dueFn->invoke(null, $fromPayload->invoke(null, $read->invoke(null, 'stem')), $nb1 + 1);
        $assert(is_array($dueLater) && (string)$dueLater['day'] === $lateDay, 'deferred job becomes due after not_before');

        sleep(1);
        $enqueueDay->invoke(null, $threeDaysAgo + 60);
        $job2 = $jobFor($lateDay);
        $assert($job2 !== null && (int)$job2['not_before'] === $nb1, 'repeat late result does not postpone');
        $assert(count($queuedDays()) === 1, 'repeat late result merges into one job');

        AudioJobQueue::requestStem(['day' => $lateDay]);
        $job3 = $jobFor($lateDay);
        $assert($job3 !== null && (int)$job3['not_before'] === 0, 'immediate ping overrides deferral');

        $todayNoon = (int)mktime(12, 0, 0, (int)date('n'), (int)date('j'), (int)date('Y'));
        $assert((string)$enqueueDay->invoke(null, $todayNoon) === '', 'today is left to the Whisper idle ping');
        $tooOld = (int)mktime(12, 0, 0, (int)date('n'), (int)date('j') - StemRunner::LOOKBACK_DAYS - 5, (int)date('Y'));
        $assert((string)$enqueueDay->invoke(null, $tooOld) === '', 'day older than lookback is not enqueued');
        $assert((string)$enqueueDay->invoke(null, time() + 3 * 86400) === '', 'future day is not enqueued');

        AudioJobQueue::clear('stem');
        $sweep = $sweepFn->invoke(null);
        $yday = date('Y-m-d', (int)mktime(0, 0, 0, (int)date('n'), (int)date('j') - 1, (int)date('Y')));
        $assert(in_array($yday, $sweep, true), 'morning sweep returns yesterday');
        $ydayJob = $jobFor($yday);
        $assert($ydayJob !== null && (int)$ydayJob['not_before'] === 0, 'morning sweep queues yesterday immediately');
    } else {
        echo "SKIP asr enqueue / sweep (classes missing)\n";
    }
} finally {
    if ($queueBackup !== null) {
        file_put_contents($queueFile, $queueBackup);
    } else {
        AudioJobQueue::clear('stem');
    }
}

// Changed day report comment must clear ticket read marks; unchanged must keep them.
if (ModuleManager::isEnabled('staff_reports')
    && class_exists('TicketWriter', false)
    && class_exists('CommentsRepository', false)
    && Database::tableExists('base_comments')
    && Database::tableExists('base_views')
) {
    $pdo = Database::pdo();
    $ticketId = 2147000001;
    $userId = 1;
    $marker = 'smoke-audio-queue-marker';
    $viewCount = static function () use ($pdo, $ticketId, $userId): int {
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM base_views WHERE ID_OBJ = ? AND TYPE_OBJ = ? AND ID_USER = ?'
        );
        $st->execute([$ticketId, 'base_news', $userId]);
        return (int)$st->fetchColumn();
    };
    $markRead = static function () use ($pdo, $ticketId, $userId): void {
        $pdo->prepare('INSERT INTO base_views SET DT = ?, ID_OBJ = ?, TYPE_OBJ = ?, ID_USER = ?')
            ->execute([time(), $ticketId, 'base_news', $userId]);
    };
    // Legacy base_comments / base_views are MyISAM — no transactions; clean up by fake ticket id.
    $cleanup = static function () use ($pdo, $ticketId): void {
        $pdo->prepare('DELETE FROM base_comments WHERE ID_OBJECT = ?')->execute([$ticketId]);
        $pdo->prepare('DELETE FROM base_views WHERE ID_OBJ = ?')->execute([$ticketId]);
    };
    $cleanup();
    try {
        $up1 = TicketWriter::upsertReportComment($ticketId, $userId, $marker, $marker . ' v1');
        $assert((int)$up1['comment_id'] > 0, 'report comment created');
        $markRead();
        $up2 = TicketWriter::upsertReportComment($ticketId, $userId, $marker, $marker . ' v1');
        $assert(empty($up2['changed']), 'same text → changed=false');
        $assert($viewCount() === 1, 'same text keeps read mark');
        $up3 = TicketWriter::upsertReportComment($ticketId, $userId, $marker, $marker . ' v2');
        $assert(!empty($up3['changed']), 'new text → changed=true');
        $assert($viewCount() === 0, 'new text clears read mark');
    } finally {
        $cleanup();
    }
} else {
    echo "SKIP staff_reports read reset (module/tables missing)\n";
}

if ($failed > 0) {
    echo "FAILED ({$failed})\n";
    exit(1);
}
echo "OK\n";
exit(0);
