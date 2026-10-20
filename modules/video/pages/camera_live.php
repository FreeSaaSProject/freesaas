<?php
declare(strict_types=1);

$id = (int)(Router::param('id') ?? 0);
$row = VideoCamerasRepository::find($id);
if ($row === null) {
    http_response_code(404);
    echo t('core.common.not_found');
    return;
}

$public = VideoCamerasRepository::forPublicView($row);
$wantsJson = AgentHttp::wantsJson();
if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'camera' => $public,
        'annotated_url' => $public['LAST_ANNOTATED_PATH'] !== ''
            ? '/video/media?rel=' . rawurlencode((string)$public['LAST_ANNOTATED_PATH'])
            : null,
        'frame_url' => $public['LAST_FRAME_PATH'] !== ''
            ? '/video/media?rel=' . rawurlencode((string)$public['LAST_FRAME_PATH'])
            : null,
        'detections' => json_decode((string)($public['LAST_DETECT_JSON'] ?? ''), true) ?: [],
    ]);
    return;
}

View::render('camera_live', [
    'title' => t('video.live.title', ['name' => (string)$row['NAME']]),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('video.menu.cameras'), 'href' => '/video/cameras'],
        ['label' => (string)$row['NAME'], 'href' => Auth::can('video.edit') ? '/video/cameras/' . $id . '/edit' : '/video/cameras'],
        ['label' => t('video.live.crumb'), 'current' => true],
    ]),
    'row' => $public,
], 'layout', 'video');
