<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$id = (int)(Router::param('id') ?? 0);
$isNew = $id <= 0;
$row = $isNew ? null : VideoCamerasRepository::find($id);
if (!$isNew && $row === null) {
    http_response_code(404);
    echo t('core.common.not_found');
    return;
}

$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string)($_POST['action'] ?? 'save');
    if ($action === 'delete' && $id > 0) {
        if (!Auth::can('video.delete')) {
            http_response_code(403);
            return;
        }
        VideoCamerasRepository::deleteByIds([$id]);
        if ($wantsJson) {
            AgentHttp::emit(['ok' => true, 'deleted' => true]);
            return;
        }
        flash_set('ok', t('video.flash.deleted'));
        redirect('/video/cameras');
    }
    try {
        $savedId = VideoCamerasRepository::save($id, [
            'name' => $_POST['name'] ?? '',
            'rtsp_url' => $_POST['rtsp_url'] ?? '',
            'mode' => $_POST['mode'] ?? 'monitoring',
            'model_id' => (int)($_POST['model_id'] ?? 0),
            'enabled' => !empty($_POST['enabled']),
            'conf_threshold' => (float)($_POST['conf_threshold'] ?? 0.25),
            'iou_threshold' => (float)($_POST['iou_threshold'] ?? 0.45),
            'interval_sec' => (int)($_POST['interval_sec'] ?? 30),
            'settings' => [
                'crop_height_pct' => (float)($_POST['crop_height_pct'] ?? 0),
                'rotate_deg' => (int)($_POST['rotate_deg'] ?? 0),
                'dataset_project_id' => (int)($_POST['dataset_project_id'] ?? 0),
            ],
        ]);
        if ($wantsJson) {
            AgentHttp::emit(['ok' => true, 'id' => $savedId]);
            return;
        }
        flash_set('ok', t('core.common.saved'));
        redirect('/video/cameras/' . $savedId . '/edit');
    } catch (Throwable $e) {
        $error = $e->getMessage();
        if ($wantsJson) {
            AgentHttp::error($error, 400);
            return;
        }
        $row = $id > 0 ? VideoCamerasRepository::find($id) : [
            'NAME' => (string)($_POST['name'] ?? ''),
            'RTSP_URL' => (string)($_POST['rtsp_url'] ?? ''),
            'MODE' => (string)($_POST['mode'] ?? 'monitoring'),
            'MODEL_ID' => (int)($_POST['model_id'] ?? 0),
            'ENABLED' => !empty($_POST['enabled']) ? 1 : 0,
            'CONF_THRESHOLD' => (float)($_POST['conf_threshold'] ?? 0.25),
            'IOU_THRESHOLD' => (float)($_POST['iou_threshold'] ?? 0.45),
            'INTERVAL_SEC' => (int)($_POST['interval_sec'] ?? 30),
            'SETTINGS_JSON' => json_encode([
                'crop_height_pct' => (float)($_POST['crop_height_pct'] ?? 0),
                'rotate_deg' => (int)($_POST['rotate_deg'] ?? 0),
                'dataset_project_id' => (int)($_POST['dataset_project_id'] ?? 0),
            ], JSON_UNESCAPED_UNICODE),
        ];
        $settings = json_decode((string)($row['SETTINGS_JSON'] ?? ''), true) ?: [];
    }
}

$models = VideoModelsRepository::listAll();
$settings = json_decode((string)($row['SETTINGS_JSON'] ?? ''), true) ?: [];

if ($wantsJson) {
    AgentHttp::emit(['ok' => true, 'camera' => $row, 'models' => $models]);
    return;
}

View::render('camera_edit', [
    'title' => $isNew ? t('video.cameras.new') : t('video.cameras.edit'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('video.menu.cameras'), 'href' => '/video/cameras'],
        ['label' => $isNew ? t('video.cameras.new') : (string)($row['NAME'] ?? ''), 'current' => true],
    ]),
    'row' => $row,
    'models' => $models,
    'settings' => $settings,
    'error' => $error,
    'isNew' => $isNew,
], 'layout', 'video');
