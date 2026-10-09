<?php
declare(strict_types=1);

require_once base_path('modules/asr/lib/AsrWorkerAuth.php');
require_once base_path('modules/audio_pipeline/lib/JobLock.php');
require_once base_path('modules/audio_pipeline/lib/JobRunner.php');
require_once base_path('modules/audio_pipeline/lib/CollectRunner.php');

AsrWorkerAuth::assertToken();
$lock = JobLock::tryAcquire('collect');
if ($lock === null) {
    JobRunner::blocked();
    return;
}
try {
    $full = !empty($_GET['full']) || !empty($_POST['full']);
    JobRunner::jsonResponse(CollectRunner::run($full));
} finally {
    $lock->release();
}
