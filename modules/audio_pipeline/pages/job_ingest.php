<?php
declare(strict_types=1);

require_once base_path('modules/audio_pipeline/lib/AsrWorkerAuth.php');
require_once base_path('modules/audio_pipeline/lib/JobLock.php');
require_once base_path('modules/audio_pipeline/lib/JobRunner.php');
require_once base_path('modules/audio_pipeline/lib/IngestRunner.php');

AsrWorkerAuth::assertToken();
$lock = JobLock::tryAcquire('ingest');
if ($lock === null) {
    JobRunner::blocked();
    return;
}
try {
    $importBroken = !empty($_GET['importBrokenDate']) || !empty($_POST['importBrokenDate']);
    JobRunner::jsonResponse(IngestRunner::run($importBroken));
} finally {
    $lock->release();
}
