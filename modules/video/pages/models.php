<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$error = '';
$message = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string)($_POST['action'] ?? 'save');
    if ($action === 'delete') {
        $ids = array_map('intval', (array)($_POST['ids'] ?? []));
        if (isset($_POST['id'])) {
            $ids[] = (int)$_POST['id'];
        }
        VideoModelsRepository::deleteByIds($ids);
        if ($wantsJson) {
            AgentHttp::emit(['ok' => true, 'deleted' => true]);
            return;
        }
        flash_set('ok', t('video.flash.deleted'));
        redirect('/video/models');
    }
    if ($action === 'enqueue_train') {
        $projectId = (int)($_POST['project_id'] ?? 0);
        $baseWeights = trim((string)($_POST['base_weights'] ?? ''));
        $epochs = max(1, (int)($_POST['epochs'] ?? 50));
        $name = trim((string)($_POST['model_name'] ?? 'train'));
        $exportRel = '';
        $exportZipRel = '';
        $classes = [];
        if ($projectId > 0 && class_exists(VideoLabelProjectsRepository::class, false)) {
            try {
                $exportRel = VideoLabelProjectsRepository::exportYolo($projectId);
                // Ship zip for Mac worker (never pass PHP-server absolute paths)
                $exportZipRel = VideoStorage::zipRelDir(
                    $exportRel,
                    'train_p' . $projectId . '_' . time() . '.zip'
                );
                $proj = VideoLabelProjectsRepository::find($projectId);
                if ($proj) {
                    foreach ($proj['classes'] as $c) {
                        $classes[] = (string)($c['name'] ?? '');
                    }
                }
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        } else {
            $error = t('video.models.err_train_project');
        }
        if ($error === '' && $exportZipRel === '') {
            $error = t('video.models.err_train_zip');
        }
        if ($error === '') {
            $jobId = VideoJobsRepository::enqueue(VideoJobsRepository::TYPE_TRAIN, [
                'project_id' => $projectId,
                'base_weights' => $baseWeights,
                'epochs' => $epochs,
                'model_name' => $name,
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
            redirect('/video/models');
        } elseif ($wantsJson) {
            AgentHttp::error($error, 400);
            return;
        }
    } elseif ($action === 'save') {
        // Register model path (weights already on worker or uploaded)
        $name = trim((string)($_POST['name'] ?? ''));
        $weights = trim((string)($_POST['weights_path'] ?? ''));
        $classesRaw = trim((string)($_POST['classes'] ?? ''));
        $classes = VideoColors::fromTextarea($classesRaw);
        if ($name === '' || $weights === '') {
            $error = t('video.models.err_required');
            if ($wantsJson) {
                AgentHttp::error($error, 400);
                return;
            }
        } else {
            if (!empty($_FILES['weights_file']['tmp_name']) && is_uploaded_file((string)$_FILES['weights_file']['tmp_name'])) {
                $bytes = (string)file_get_contents((string)$_FILES['weights_file']['tmp_name']);
                $fn = (string)($_FILES['weights_file']['name'] ?? 'model.pt');
                $weights = VideoStorage::putBytes('models', $fn, $bytes);
            }
            VideoModelsRepository::save(0, $name, $weights, $classes, trim((string)($_POST['notes'] ?? '')));
            if ($wantsJson) {
                AgentHttp::emit(['ok' => true]);
                return;
            }
            flash_set('ok', t('core.common.saved'));
            redirect('/video/models');
        }
    }
}

$rows = VideoModelsRepository::listAll();
$labelProjects = class_exists(VideoLabelProjectsRepository::class, false)
    ? VideoLabelProjectsRepository::listBrief()
    : [];

if ($wantsJson) {
    AgentHttp::emit(['ok' => true, 'models' => $rows, 'label_projects' => $labelProjects]);
    return;
}

View::render('models', [
    'title' => t('video.models.title'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('video.menu.models'), 'current' => true],
    ]),
    'rows' => $rows,
    'labelProjects' => $labelProjects,
    'error' => $error,
    'message' => $message,
], 'layout', 'video');
