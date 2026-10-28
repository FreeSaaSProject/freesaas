<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$projectId = (int)(Router::param('id') ?? 0);
$proj = VideoLabelProjectsRepository::find($projectId);
if ($proj === null) {
    http_response_code(404);
    echo t('core.common.not_found');
    return;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'ingest_zip' && !empty($_FILES['zip'])) {
        $file = $_FILES['zip'];
        if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            flash_set('error', t('video_label.flash.zip_failed'));
            redirect('/video-label/' . $projectId . '/workspace');
        }
        $result = VideoLabelZipIngest::fromUpload(
            $projectId,
            (string)$file['tmp_name'],
            (string)($file['name'] ?? 'dataset.zip')
        );
        if ($wantsJson) {
            if (!empty($result['ok'])) {
                AgentHttp::emit($result);
            } else {
                AgentHttp::error((string)($result['error'] ?? 'ingest failed'), 422);
            }
            return;
        }
        if (!empty($result['ok'])) {
            flash_set('ok', t('video_label.flash.zip_imported', [
                'n' => (string)(int)($result['imported'] ?? 0),
                'labeled' => (string)(int)($result['labeled'] ?? 0),
            ]));
        } else {
            flash_set('error', t('video_label.flash.zip_failed') . ': ' . (string)($result['error'] ?? ''));
        }
        redirect('/video-label/' . $projectId . '/workspace');
    }
    if ($action === 'upload' && !empty($_FILES['images'])) {
        $files = $_FILES['images'];
        $names = $files['name'] ?? [];
        if (!is_array($names)) {
            $names = [$names];
            $files = [
                'name' => $names,
                'tmp_name' => [$files['tmp_name']],
                'error' => [$files['error']],
            ];
        }
        $count = count($names);
        for ($i = 0; $i < $count; $i++) {
            if ((int)($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                continue;
            }
            $tmp = (string)$files['tmp_name'][$i];
            $fn = (string)$files['name'][$i];
            $bytes = (string)file_get_contents($tmp);
            if (!class_exists(VideoStorage::class, false)) {
                continue;
            }
            $rel = VideoStorage::putBytes('labels/' . $projectId, $fn, $bytes);
            VideoLabelImagesRepository::add($projectId, basename($rel), $rel);
        }
        if ($wantsJson) {
            AgentHttp::emit(['ok' => true]);
            return;
        }
        flash_set('ok', t('video_label.flash.uploaded'));
        redirect('/video-label/' . $projectId . '/workspace');
    }
    if ($action === 'save_labels') {
        $imageId = (int)($_POST['image_id'] ?? 0);
        $raw = (string)($_POST['labels_json'] ?? '[]');
        $labels = json_decode($raw, true);
        if (!is_array($labels)) {
            $labels = [];
        }
        VideoLabelImagesRepository::setLabels($imageId, $labels);
        if ($wantsJson) {
            AgentHttp::emit(['ok' => true]);
            return;
        }
        flash_set('ok', t('core.common.saved'));
        redirect('/video-label/' . $projectId . '/workspace?image=' . $imageId);
    }
    if ($action === 'promote_preds') {
        $imageId = (int)($_POST['image_id'] ?? 0);
        $img = VideoLabelImagesRepository::find($imageId);
        if ($img) {
            $preds = json_decode((string)($img['PREDICTIONS_JSON'] ?? ''), true) ?: [];
            $labels = json_decode((string)($img['LABELS_JSON'] ?? ''), true) ?: [];
            $merged = array_merge(is_array($labels) ? $labels : [], is_array($preds) ? $preds : []);
            VideoLabelImagesRepository::setLabels($imageId, $merged);
        }
        if ($wantsJson) {
            AgentHttp::emit(['ok' => true]);
            return;
        }
        redirect('/video-label/' . $projectId . '/workspace?image=' . $imageId);
    }
    if ($action === 'clear_predictions') {
        $n = VideoLabelProjectsRepository::scrubDuplicatePredictions($projectId);
        // Also wipe all predictions so a fresh infer can refill (LabelForge cache reset).
        foreach (VideoLabelImagesRepository::listForProject($projectId) as $img) {
            VideoLabelImagesRepository::setPredictions((int)$img['ID'], []);
        }
        if ($wantsJson) {
            AgentHttp::emit(['ok' => true, 'scrubbed_dupes' => $n, 'cleared' => true]);
            return;
        }
        flash_set('ok', t('video_label.flash.predictions_cleared'));
        redirect('/video-label/' . $projectId . '/workspace');
    }
    if ($action === 'infer') {
        $imageId = (int)($_POST['image_id'] ?? 0);
        $batch = !empty($_POST['batch']);
        if (!class_exists(VideoJobsRepository::class, false)) {
            flash_set('error', t('video_label.flash.need_video'));
            redirect('/video-label/' . $projectId . '/workspace');
        }
        $classes = is_array($proj['classes'] ?? null) ? $proj['classes'] : [];
        if ($batch) {
            $items = [];
            foreach (VideoLabelImagesRepository::listForProject($projectId) as $img) {
                $items[] = ['image_id' => (int)$img['ID'], 'image_rel' => (string)$img['PATH']];
            }
            VideoJobsRepository::enqueue(VideoJobsRepository::TYPE_INFER_BATCH, [
                'project_id' => $projectId,
                'model_id' => (int)$proj['MODEL_ID'],
                'conf_threshold' => (float)$proj['CONF_THRESHOLD'],
                'detect_conf' => 0.01,
                'iou_threshold' => (float)$proj['IOU_THRESHOLD'],
                'classes' => $classes,
                'images' => $items,
                'promote' => !empty($_POST['promote']),
            ]);
        } else {
            $img = VideoLabelImagesRepository::find($imageId);
            if ($img) {
                VideoJobsRepository::enqueue(VideoJobsRepository::TYPE_INFER_IMAGE, [
                    'project_id' => $projectId,
                    'model_id' => (int)$proj['MODEL_ID'],
                    'conf_threshold' => (float)$proj['CONF_THRESHOLD'],
                    'detect_conf' => 0.01,
                    'iou_threshold' => (float)$proj['IOU_THRESHOLD'],
                    'classes' => $classes,
                    'image_id' => $imageId,
                    'image_rel' => (string)$img['PATH'],
                    'promote' => !empty($_POST['promote']),
                ]);
            }
        }
        if ($wantsJson) {
            AgentHttp::emit(['ok' => true, 'enqueued' => true]);
            return;
        }
        flash_set('ok', t('video_label.flash.infer_enqueued'));
        redirect('/video-label/' . $projectId . '/workspace' . ($imageId ? '?image=' . $imageId : ''));
    }
    if ($action === 'export') {
        try {
            $rel = VideoLabelProjectsRepository::exportYolo($projectId);
            if ($wantsJson) {
                AgentHttp::emit(['ok' => true, 'export_rel' => $rel]);
                return;
            }
            flash_set('ok', t('video_label.flash.exported', ['path' => $rel]));
        } catch (Throwable $e) {
            flash_set('error', $e->getMessage());
        }
        redirect('/video-label/' . $projectId . '/workspace');
    }
    if ($action === 'set_split') {
        VideoLabelImagesRepository::setSplit((int)($_POST['image_id'] ?? 0), (string)($_POST['split'] ?? 'train'));
        redirect('/video-label/' . $projectId . '/workspace?image=' . (int)($_POST['image_id'] ?? 0));
    }
    if ($action === 'delete_image') {
        VideoLabelImagesRepository::delete((int)($_POST['image_id'] ?? 0));
        redirect('/video-label/' . $projectId . '/workspace');
    }
    if ($action === 'bulk') {
        $idsRaw = $_POST['ids'] ?? [];
        if (is_string($idsRaw)) {
            $decoded = json_decode($idsRaw, true);
            $idsRaw = is_array($decoded) ? $decoded : (preg_split('/\s*,\s*/', $idsRaw) ?: []);
        }
        $ids = [];
        foreach ((array)$idsRaw as $v) {
            $id = (int)$v;
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        $ids = array_values(array_unique($ids));
        $op = (string)($_POST['bulk_op'] ?? '');
        $n = 0;
        if ($op === 'split') {
            $n = VideoLabelImagesRepository::setSplitMany($ids, (string)($_POST['split'] ?? 'train'));
            flash_set('ok', t('video_label.flash.bulk_done', ['n' => (string)$n]));
        } elseif ($op === 'auto_split') {
            $stats = VideoLabelImagesRepository::autoSplitMany($ids);
            $n = (int)$stats['n'];
            flash_set('ok', t('video_label.flash.auto_split', [
                'n' => (string)$n,
                'train' => (string)$stats['train'],
                'val' => (string)$stats['val'],
                'test' => (string)$stats['test'],
            ]));
            if ($wantsJson) {
                AgentHttp::emit(['ok' => true, 'bulk_op' => $op] + $stats);
                return;
            }
            redirect('/video-label/' . $projectId . '/workspace');
        } elseif ($op === 'clear_labels') {
            $n = VideoLabelImagesRepository::clearLabelsMany($ids);
            flash_set('ok', t('video_label.flash.bulk_done', ['n' => (string)$n]));
        } elseif ($op === 'clear_preds') {
            $n = VideoLabelImagesRepository::clearPredictionsMany($ids);
            flash_set('ok', t('video_label.flash.bulk_done', ['n' => (string)$n]));
        } elseif ($op === 'delete') {
            $n = VideoLabelImagesRepository::deleteMany($ids);
            flash_set('ok', t('video_label.flash.bulk_done', ['n' => (string)$n]));
        } else {
            $n = 0;
            flash_set('ok', t('video_label.flash.bulk_done', ['n' => '0']));
        }
        if ($wantsJson) {
            AgentHttp::emit(['ok' => true, 'n' => $n, 'bulk_op' => $op]);
            return;
        }
        redirect('/video-label/' . $projectId . '/workspace');
    }
}

// One-time heal: zip ingest used to mirror labels into predictions without conf.
VideoLabelProjectsRepository::scrubDuplicatePredictions($projectId);

$images = VideoLabelImagesRepository::listForProject($projectId);
$currentId = (int)($_GET['image'] ?? 0);
if ($currentId <= 0 && $images !== []) {
    $currentId = (int)$images[0]['ID'];
}
$current = null;
foreach ($images as $img) {
    if ((int)$img['ID'] === $currentId) {
        $current = $img;
        break;
    }
}

$payload = [
    'project' => [
        'id' => $projectId,
        'name' => (string)$proj['NAME'],
        'classes' => $proj['classes'],
        'conf' => (float)$proj['CONF_THRESHOLD'],
        'iou' => (float)$proj['IOU_THRESHOLD'],
    ],
    'images' => array_map(static function (array $img): array {
        $labels = json_decode((string)($img['LABELS_JSON'] ?? ''), true) ?: [];
        $preds = json_decode((string)($img['PREDICTIONS_JSON'] ?? ''), true) ?: [];
        if (!is_array($labels)) {
            $labels = [];
        }
        if (!is_array($preds)) {
            $preds = [];
        }
        $classCounts = [];
        foreach ($labels as $b) {
            if (!is_array($b)) {
                continue;
            }
            $cid = (int)($b['class_id'] ?? 0);
            $classCounts[$cid] = ($classCounts[$cid] ?? 0) + 1;
        }

        return [
            'id' => (int)$img['ID'],
            'filename' => (string)$img['FILENAME'],
            'url' => '/video-label/media?rel=' . rawurlencode((string)$img['PATH']),
            'split' => (string)$img['SPLIT'],
            'label_count' => count($labels),
            'has_preds' => $preds !== [],
            'class_counts' => $classCounts,
            'labels' => $labels,
            'predictions' => $preds,
        ];
    }, $images),
    'current_id' => $currentId,
];

if ($wantsJson) {
    AgentHttp::emit(['ok' => true] + $payload);
    return;
}

View::render('workspace', [
    'title' => t('video_label.workspace.title', ['name' => (string)$proj['NAME']]),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('video_label.menu.projects'), 'href' => '/video-label'],
        ['label' => (string)$proj['NAME'], 'current' => true],
    ]),
    'main_flush' => true,
    'project' => $proj,
    'images' => $images,
    'current' => $current,
    'currentId' => $currentId,
    'workspaceJson' => json_encode($payload, JSON_UNESCAPED_UNICODE),
], 'layout', 'video_label');
