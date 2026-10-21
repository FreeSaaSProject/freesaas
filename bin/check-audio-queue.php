#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * AudioJobQueue stem RMW: concurrent enqueue must not be wiped by head drop.
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

AudioJobQueue::clear('stem');
$r1 = AudioJobQueue::requestStem(['day' => '2099-01-01', 'admin' => 1, 'collect' => false, 'publish' => false]);
$assert(!empty($r1['ok']), 'enqueue day A');
$r2 = AudioJobQueue::requestStem(['day' => '2099-01-02', 'admin' => 2, 'collect' => false, 'publish' => false]);
$assert(!empty($r2['ok']), 'enqueue day B');
$assert((int)($r2['jobs'] ?? 0) === 2, 'two scopes queued');

$ref = new ReflectionClass(AudioJobQueue::class);
$drop = $ref->getMethod('dropStemJobByKey');
$drop->setAccessible(true);
$withLock = $ref->getMethod('withStemQueueLock');
$withLock->setAccessible(true);
$read = $ref->getMethod('read');
$read->setAccessible(true);
$fromPayload = $ref->getMethod('stemJobsFromPayload');
$fromPayload->setAccessible(true);
$persist = $ref->getMethod('persistStemJobs');
$persist->setAccessible(true);
$keyFn = $ref->getMethod('stemJobKey');
$keyFn->setAccessible(true);
$fromOpts = $ref->getMethod('stemJobFromOpts');
$fromOpts->setAccessible(true);

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

AudioJobQueue::clear('stem');

if ($failed > 0) {
    echo "FAILED ({$failed})\n";
    exit(1);
}
echo "OK\n";
exit(0);
