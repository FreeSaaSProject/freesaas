<?php
declare(strict_types=1);

require_once base_path('modules/asr/lib/AsrWorkerAuth.php');
require_once base_path('modules/audio_pipeline/lib/JobLock.php');
require_once base_path('modules/audio_pipeline/lib/JobRunner.php');
require_once base_path('modules/audio_pipeline/lib/CollectRunner.php');

AsrWorkerAuth::assertToken();
$lock = JobLock::tryAcquire('collect');
if ($lock === null) {
    JobRunner::blocked('collect');
    return;
}
try {
    $full = !empty($_GET['full']) || !empty($_POST['full']);
    JobRunner::jsonResponse(CollectRunner::run($full));
} catch (Throwable $e) {
    error_log('[audio/jobs/collect] ' . $e->getMessage());
    $msg = $e->getMessage();
    $timedOut = (strpos($msg, '1317') !== false || stripos($msg, 'interrupted') !== false);
    JobRunner::jsonResponse([
        'ok' => false,
        'processed' => 0,
        'skipped' => 0,
        'message' => $timedOut ? 'query_interrupted' : 'collect_error',
        'extra' => ['error' => $msg],
    ], $timedOut ? 504 : 500);
} finally {
    $lock->release();
}
