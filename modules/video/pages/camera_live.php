<?php
declare(strict_types=1);

$id = (int)(Router::param('id') ?? 0);
$row = VideoCamerasRepository::find($id);
if ($row === null) {
    http_response_code(404);
    echo t('core.common.not_found');
    return;
}

$wantsJson = AgentHttp::wantsJson();
if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'camera' => $row,
        'annotated_url' => $row['LAST_ANNOTATED_PATH'] !== ''
            ? '/video/media?rel=' . rawurlencode((string)$row['LAST_ANNOTATED_PATH'])
            : null,
        'frame_url' => $row['LAST_FRAME_PATH'] !== ''
            ? '/video/media?rel=' . rawurlencode((string)$row['LAST_FRAME_PATH'])
            : null,
        'detections' => json_decode((string)($row['LAST_DETECT_JSON'] ?? ''), true) ?: [],
    ]);
    return;
}

View::render('camera_live', [
    'title' => t('video.live.title', ['name' => (string)$row['NAME']]),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('video.menu.cameras'), 'href' => '/video/cameras'],
        ['label' => (string)$row['NAME'], 'href' => '/video/cameras/' . $id . '/edit'],
        ['label' => t('video.live.crumb'), 'current' => true],
    ]),
    'row' => $row,
], 'layout', 'video');
