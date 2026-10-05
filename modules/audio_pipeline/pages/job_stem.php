<?php
declare(strict_types=1);

require_once base_path('modules/audio_pipeline/lib/AsrWorkerAuth.php');
require_once base_path('modules/audio_pipeline/lib/JobLock.php');
require_once base_path('modules/audio_pipeline/lib/JobRunner.php');
require_once base_path('modules/audio_pipeline/lib/StemRunner.php');

AsrWorkerAuth::assertToken();
$lock = JobLock::tryAcquire('stem');
if ($lock === null) {
    JobRunner::blocked();
    return;
}
try {
    $result = StemRunner::run();
    $http = ($result['message'] === 'stem_libs_unavailable') ? 503 : 200;
    if ($result['message'] === 'stem_not_implemented') {
        $http = 501;
    }
    JobRunner::jsonResponse($result, $http);
} finally {
    $lock->release();
}
