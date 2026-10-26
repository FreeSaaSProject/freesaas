<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$step = max(1, min(5, (int)($_GET['step'] ?? $_POST['step'] ?? 1)));
$error = '';
$postId = (int)($_GET['post_id'] ?? $_POST['post_id'] ?? 0);
$cameraId = (int)($_GET['camera_id'] ?? $_POST['camera_id'] ?? 0);
$modelId = (int)($_GET['model_id'] ?? $_POST['model_id'] ?? 0);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string)($_POST['action'] ?? 'next');
    try {
        if ($step === 1 && $action !== 'skip') {
            $postId = VideoPostsRepository::save($postId, [
                'name' => $_POST['name'] ?? '',
                'enabled' => !empty($_POST['enabled']),
                'model_id' => 0,
                'settings' => [],
            ]);
            $step = 2;
        } elseif ($step === 2) {
            $settings = [
                'crop_height_pct' => (float)($_POST['crop_height_pct'] ?? 0),
                'rotate_deg' => (int)($_POST['rotate_deg'] ?? 0),
            ];
            $cameraId = VideoCamerasRepository::save($cameraId, [
                'name' => $_POST['name'] ?? '',
                'rtsp_url' => $_POST['rtsp_url'] ?? '',
                'mode' => $_POST['mode'] ?? 'monitoring',
                'model_id' => $modelId,
                'post_id' => $postId,
                'enabled' => !empty($_POST['enabled']),
                'conf_threshold' => (float)($_POST['conf_threshold'] ?? 0.25),
                'iou_threshold' => (float)($_POST['iou_threshold'] ?? 0.45),
                'interval_sec' => (int)($_POST['interval_sec'] ?? 30),
                'settings' => $settings,
            ]);
            if (!empty($_POST['add_another'])) {
                $cameraId = 0;
                // stay on step 2
            } else {
                $step = 3;
            }
        } elseif ($step === 3) {
            $classes = VideoColors::fromTextarea((string)($_POST['classes'] ?? ''));
            $weights = trim((string)($_POST['weights_path'] ?? ''));
            $name = trim((string)($_POST['model_name'] ?? 'spa-detect'));
            if ($weights === '' && $modelId <= 0) {
                throw new RuntimeException(t('video.models.err_required'));
            }
            if ($weights !== '' || $modelId > 0) {
                if ($weights === '' && $modelId > 0) {
                    $m = VideoModelsRepository::find($modelId);
                    $weights = $m ? (string)$m['WEIGHTS_PATH'] : '';
                }
                $modelId = VideoModelsRepository::save($modelId, $name !== '' ? $name : 'spa-detect', $weights, $classes, '');
            }
            if ($postId > 0) {
                $post = VideoPostsRepository::find($postId);
                $ps = json_decode((string)($post['SETTINGS_JSON'] ?? ''), true) ?: [];
                VideoPostsRepository::save($postId, [
                    'name' => (string)($post['NAME'] ?? ''),
                    'enabled' => !empty($post['ENABLED']),
                    'model_id' => $modelId,
                    'settings' => $ps,
                ]);
            }
            if ($cameraId > 0) {
                $cam = VideoCamerasRepository::find($cameraId);
                if ($cam) {
                    VideoCamerasRepository::save($cameraId, [
                        'name' => (string)$cam['NAME'],
                        'rtsp_url' => (string)$cam['RTSP_URL'],
                        'mode' => (string)$cam['MODE'],
                        'model_id' => $modelId,
                        'post_id' => (int)$cam['POST_ID'],
                        'enabled' => !empty($cam['ENABLED']),
                        'conf_threshold' => (float)$cam['CONF_THRESHOLD'],
                        'iou_threshold' => (float)$cam['IOU_THRESHOLD'],
                        'interval_sec' => (int)$cam['INTERVAL_SEC'],
                        'settings' => json_decode((string)($cam['SETTINGS_JSON'] ?? ''), true) ?: [],
                    ]);
                }
            }
            $step = 4;
        } elseif ($step === 4) {
            $settings = [];
            if ($postId > 0) {
                $post = VideoPostsRepository::find($postId);
                $settings = json_decode((string)($post['SETTINGS_JSON'] ?? ''), true) ?: [];
            }
            $settings = VideoSchedule::mergeFromPost($_POST, $settings);
            if ($postId > 0) {
                $post = VideoPostsRepository::find($postId);
                VideoPostsRepository::save($postId, [
                    'name' => (string)($post['NAME'] ?? ''),
                    'enabled' => !empty($post['ENABLED']),
                    'model_id' => (int)($post['MODEL_ID'] ?? $modelId),
                    'settings' => $settings,
                ]);
            }
            if ($cameraId > 0) {
                $cam = VideoCamerasRepository::find($cameraId);
                if ($cam) {
                    $cs = json_decode((string)($cam['SETTINGS_JSON'] ?? ''), true) ?: [];
                    $cs = VideoSchedule::mergeFromPost($_POST, $cs);
                    VideoCamerasRepository::save($cameraId, [
                        'name' => (string)$cam['NAME'],
                        'rtsp_url' => (string)$cam['RTSP_URL'],
                        'mode' => (string)$cam['MODE'],
                        'model_id' => (int)$cam['MODEL_ID'] ?: $modelId,
                        'post_id' => (int)$cam['POST_ID'] ?: $postId,
                        'enabled' => !empty($cam['ENABLED']),
                        'conf_threshold' => (float)$cam['CONF_THRESHOLD'],
                        'iou_threshold' => (float)$cam['IOU_THRESHOLD'],
                        'interval_sec' => (int)$cam['INTERVAL_SEC'],
                        'settings' => $cs,
                    ]);
                }
            }
            $step = 5;
        } elseif ($step === 5) {
            if ($cameraId > 0) {
                $cam = VideoCamerasRepository::find($cameraId);
                if ($cam) {
                    VideoJobsRepository::enqueue(
                        VideoJobsRepository::TYPE_ON_DEMAND,
                        array_merge(VideoCamerasRepository::jobPayload($cam), ['annotate' => true])
                    );
                }
            }
            if ($wantsJson) {
                AgentHttp::emit(['ok' => true, 'post_id' => $postId, 'camera_id' => $cameraId, 'model_id' => $modelId]);
                return;
            }
            flash_set('ok', t('video.wizard.setup.done'));
            redirect($postId > 0 ? '/video/posts/' . $postId : '/video/posts');
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
        if ($wantsJson) {
            AgentHttp::error($error, 400);
            return;
        }
    }
    if ($error === '' && !$wantsJson) {
        redirect('/video/wizard/setup?step=' . $step
            . '&post_id=' . $postId
            . '&camera_id=' . $cameraId
            . '&model_id=' . $modelId);
    }
}

$post = $postId > 0 ? VideoPostsRepository::find($postId) : null;
$camera = $cameraId > 0 ? VideoCamerasRepository::find($cameraId) : null;
$model = $modelId > 0 ? VideoModelsRepository::find($modelId) : null;
$models = VideoModelsRepository::listAll();
$sched = ['mode' => 'always'];
if ($post) {
    $ps = json_decode((string)($post['SETTINGS_JSON'] ?? ''), true) ?: [];
    $sched = $ps['schedule'] ?? $sched;
}

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'step' => $step,
        'post_id' => $postId,
        'camera_id' => $cameraId,
        'model_id' => $modelId,
    ]);
    return;
}

View::render('wizard_setup', [
    'title' => t('video.wizard.setup'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('video.menu.posts'), 'href' => '/video/posts'],
        ['label' => t('video.wizard.setup'), 'current' => true],
    ]),
    'step' => $step,
    'postId' => $postId,
    'cameraId' => $cameraId,
    'modelId' => $modelId,
    'post' => $post,
    'camera' => $camera,
    'model' => $model,
    'models' => $models,
    'sched' => $sched,
    'error' => $error,
], 'layout', 'video');
