<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$step = max(1, min(4, (int)($_GET['step'] ?? $_POST['step'] ?? 1)));
$error = '';
$sessionId = (int)($_GET['session_id'] ?? $_POST['session_id'] ?? 0);
$postId = (int)($_GET['post_id'] ?? $_POST['post_id'] ?? 0);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        if ($step === 1) {
            $cameraId = (int)($_POST['camera_id'] ?? 0);
            $projectId = (int)($_POST['project_id'] ?? 0);
            $targetClass = (int)($_POST['target_class_id'] ?? 0);
            if ($cameraId <= 0 || $projectId <= 0) {
                throw new RuntimeException(t('video.wizard.train.err_required'));
            }
            $cam = VideoCamerasRepository::find($cameraId);
            $postId = $postId > 0 ? $postId : (int)($cam['POST_ID'] ?? 0);
            $sessionId = VideoTrainSessionsRepository::create([
                'post_id' => $postId,
                'camera_id' => $cameraId,
                'project_id' => $projectId,
                'phase' => VideoTrainSessionsRepository::PHASE_SOLO,
                'target_class_id' => $targetClass,
                'source_model' => (string)($_POST['source_model'] ?? 'stock_yolo'),
                'person_class' => (string)($_POST['person_class'] ?? 'person'),
                'interval_ms' => (int)($_POST['interval_ms'] ?? 500),
            ]);
            $step = 2;
        } elseif ($step === 2) {
            // Coverage coach — continue to phase B
            if ($sessionId > 0) {
                $otherClass = (int)($_POST['other_class_id'] ?? 0);
                VideoTrainSessionsRepository::setPhase(
                    $sessionId,
                    VideoTrainSessionsRepository::PHASE_OTHER,
                    $otherClass > 0 ? $otherClass : null
                );
                VideoTrainSessionsRepository::setStatus($sessionId, VideoTrainSessionsRepository::STATUS_RUNNING);
            }
            $step = 3;
        } elseif ($step === 3) {
            if ($sessionId > 0) {
                VideoTrainSessionsRepository::setPhase($sessionId, VideoTrainSessionsRepository::PHASE_DONE);
                VideoTrainSessionsRepository::setStatus($sessionId, VideoTrainSessionsRepository::STATUS_FINISHED);
            }
            $step = 4;
        } elseif ($step === 4) {
            if (!empty($_POST['do_train']) && $sessionId > 0) {
                $sess = VideoTrainSessionsRepository::find($sessionId);
                if ($sess && (int)$sess['PROJECT_ID'] > 0 && class_exists(VideoLabelProjectsRepository::class, false)) {
                    $exportRel = VideoLabelProjectsRepository::exportYolo((int)$sess['PROJECT_ID']);
                    $exportZipRel = VideoStorage::zipRelDir($exportRel, 'train_wiz_' . time() . '.zip');
                    $classes = [];
                    $proj = VideoLabelProjectsRepository::find((int)$sess['PROJECT_ID']);
                    if ($proj) {
                        foreach ($proj['classes'] as $c) {
                            $classes[] = (string)($c['name'] ?? '');
                        }
                    }
                    VideoJobsRepository::enqueue(VideoJobsRepository::TYPE_TRAIN, [
                        'project_id' => (int)$sess['PROJECT_ID'],
                        'base_weights' => 'yolo11n.pt',
                        'epochs' => 50,
                        'model_name' => 'interactive',
                        'imgsz' => 640,
                        'export_rel' => $exportRel,
                        'export_zip_rel' => $exportZipRel,
                        'classes' => $classes,
                    ]);
                }
            }
            if ($wantsJson) {
                AgentHttp::emit(['ok' => true, 'session_id' => $sessionId]);
                return;
            }
            flash_set('ok', t('video.wizard.train.done'));
            redirect($sessionId > 0 ? '/video/train/' . $sessionId : '/video/train');
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
        if ($wantsJson) {
            AgentHttp::error($error, 400);
            return;
        }
    }
    if ($error === '' && !$wantsJson) {
        redirect('/video/wizard/train?step=' . $step
            . '&session_id=' . $sessionId
            . '&post_id=' . $postId);
    }
}

$posts = VideoPostsRepository::listAll();
$cameras = $postId > 0
    ? VideoCamerasRepository::listByPost($postId)
    : VideoCamerasRepository::list();
$projects = class_exists(VideoLabelProjectsRepository::class, false)
    ? VideoLabelProjectsRepository::listAll()
    : [];
$session = $sessionId > 0 ? VideoTrainSessionsRepository::find($sessionId) : null;
$coverage = $session
    ? (json_decode((string)($session['COVERAGE_JSON'] ?? ''), true) ?: VideoTrainSessionsRepository::emptyCoverage())
    : VideoTrainSessionsRepository::emptyCoverage();

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'step' => $step,
        'session_id' => $sessionId,
        'coverage' => $coverage,
    ]);
    return;
}

View::render('wizard_train', [
    'title' => t('video.wizard.train'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('video.menu.train'), 'href' => '/video/train'],
        ['label' => t('video.wizard.train'), 'current' => true],
    ]),
    'step' => $step,
    'sessionId' => $sessionId,
    'postId' => $postId,
    'posts' => $posts,
    'cameras' => $cameras,
    'projects' => $projects,
    'session' => $session,
    'coverage' => $coverage,
    'error' => $error,
], 'layout', 'video');
