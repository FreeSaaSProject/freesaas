<?php
declare(strict_types=1);

$id = (int)(Router::param('id') ?? 0);
$row = VideoTrainSessionsRepository::find($id);
if ($row === null) {
    http_response_code(404);
    echo t('core.common.not_found');
    return;
}

$wantsJson = AgentHttp::wantsJson();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'pause') {
        VideoTrainSessionsRepository::setStatus($id, VideoTrainSessionsRepository::STATUS_PAUSED);
    } elseif ($action === 'resume') {
        VideoTrainSessionsRepository::setStatus($id, VideoTrainSessionsRepository::STATUS_RUNNING);
    } elseif ($action === 'finish') {
        VideoTrainSessionsRepository::setPhase($id, VideoTrainSessionsRepository::PHASE_DONE);
        VideoTrainSessionsRepository::setStatus($id, VideoTrainSessionsRepository::STATUS_FINISHED);
    } elseif ($action === 'phase_other') {
        $cls = (int)($_POST['target_class_id'] ?? $row['TARGET_CLASS_ID']);
        VideoTrainSessionsRepository::setPhase($id, VideoTrainSessionsRepository::PHASE_OTHER, $cls);
        VideoTrainSessionsRepository::setStatus($id, VideoTrainSessionsRepository::STATUS_RUNNING);
    } elseif ($action === 'phase_solo') {
        $cls = (int)($_POST['target_class_id'] ?? $row['TARGET_CLASS_ID']);
        VideoTrainSessionsRepository::setPhase($id, VideoTrainSessionsRepository::PHASE_SOLO, $cls);
        VideoTrainSessionsRepository::setStatus($id, VideoTrainSessionsRepository::STATUS_RUNNING);
    } elseif ($action === 'enqueue_train' && (int)$row['PROJECT_ID'] > 0) {
        $exportZipRel = '';
        $exportRel = '';
        $classes = [];
        if (class_exists(VideoLabelProjectsRepository::class, false)) {
            $exportRel = VideoLabelProjectsRepository::exportYolo((int)$row['PROJECT_ID']);
            $exportZipRel = VideoStorage::zipRelDir($exportRel, 'train_sess' . $id . '_' . time() . '.zip');
            $proj = VideoLabelProjectsRepository::find((int)$row['PROJECT_ID']);
            if ($proj) {
                foreach ($proj['classes'] as $c) {
                    $classes[] = (string)($c['name'] ?? '');
                }
            }
        }
        if ($exportZipRel !== '') {
            $jobId = VideoJobsRepository::enqueue(VideoJobsRepository::TYPE_TRAIN, [
                'project_id' => (int)$row['PROJECT_ID'],
                'base_weights' => trim((string)($_POST['base_weights'] ?? 'yolo11n.pt')),
                'epochs' => max(1, (int)($_POST['epochs'] ?? 50)),
                'model_name' => trim((string)($_POST['model_name'] ?? 'interactive')),
                'imgsz' => (int)($_POST['imgsz'] ?? 640),
                'export_rel' => $exportRel,
                'export_zip_rel' => $exportZipRel,
                'classes' => $classes,
            ]);
            if ($wantsJson) {
                AgentHttp::emit(['ok' => true, 'job_id' => $jobId]);
                return;
            }
            flash_set('ok', t('video.flash.train_enqueued', ['id' => (string)$jobId]));
            redirect('/video/train/' . $id);
        }
    }
    $row = VideoTrainSessionsRepository::find($id) ?: $row;
    if ($wantsJson) {
        AgentHttp::emit(['ok' => true, 'session' => $row]);
        return;
    }
    redirect('/video/train/' . $id);
}

$coverage = json_decode((string)($row['COVERAGE_JSON'] ?? ''), true) ?: VideoTrainSessionsRepository::emptyCoverage();
$stats = json_decode((string)($row['STATS_JSON'] ?? ''), true) ?: [];
$cam = VideoCamerasRepository::find((int)$row['CAMERA_ID']);
$project = null;
$projectClasses = [];
if ((int)$row['PROJECT_ID'] > 0 && class_exists(VideoLabelProjectsRepository::class, false)) {
    $project = VideoLabelProjectsRepository::find((int)$row['PROJECT_ID']);
    $projectClasses = $project['classes'] ?? [];
}

$payload = [
    'ok' => true,
    'session' => $row,
    'coverage' => $coverage,
    'stats' => $stats,
    'annotated_url' => ((string)($row['LAST_ANNOTATED_PATH'] ?? '') !== '')
        ? '/video/media?rel=' . rawurlencode((string)$row['LAST_ANNOTATED_PATH']) . '&t=' . (int)$row['UPDATED_AT']
        : null,
    'frame_url' => ((string)($row['LAST_FRAME_PATH'] ?? '') !== '')
        ? '/video/media?rel=' . rawurlencode((string)$row['LAST_FRAME_PATH']) . '&t=' . (int)$row['UPDATED_AT']
        : null,
    'project_classes' => $projectClasses,
];

if ($wantsJson) {
    AgentHttp::emit($payload);
    return;
}

View::render('train_session', [
    'title' => t('video.train.session', ['id' => (string)$id]),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('video.menu.train'), 'href' => '/video/train'],
        ['label' => '#' . $id, 'current' => true],
    ]),
    'row' => $row,
    'coverage' => $coverage,
    'stats' => $stats,
    'cam' => $cam,
    'project' => $project,
    'projectClasses' => $projectClasses,
], 'layout', 'video');
