<?php
declare(strict_types=1);

/**
 * Enqueue inbox ingest (fast). Uploader / settings copy-paste URL:
 *   GET /audio/jobs/ingest?token=WORKER_TOKEN
 * Heavy work: Cron audio_pipeline.ingest (every 5 min) + nightly 02:00,
 * or sync processor GET|POST /audio/jobs/ingest/run?token=…
 */

require_once base_path('modules/asr/lib/AsrWorkerAuth.php');
require_once base_path('modules/audio_pipeline/lib/JobRunner.php');
require_once base_path('modules/audio_pipeline/lib/AudioJobQueue.php');

AsrWorkerAuth::assertToken();
$req = array_merge($_GET ?? [], $_POST ?? []);
$importBroken = !empty($req['importBrokenDate']) && (string)$req['importBrokenDate'] !== '0';
$out = AudioJobQueue::requestIngest($importBroken);
JobRunner::queuedResponse($out);
