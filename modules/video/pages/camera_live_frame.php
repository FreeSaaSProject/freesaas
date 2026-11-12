<?php
declare(strict_types=1);

$id = (int)(Router::param('id') ?? 0);
$row = VideoCamerasRepository::find($id);
if ($row === null) {
    AgentHttp::error(t('core.common.not_found'), 404);
    return;
}

$frame = VideoPushDispatcher::liveFrame($row);
if (!$frame['ok']) {
    AgentHttp::emit(['ok' => false, 'error' => $frame['error']]);
    return;
}

AgentHttp::emit([
    'ok' => true,
    'image' => $frame['image'],
    'detections' => $frame['detections'],
    'last_seen' => $frame['at'],
    'source' => 'edge',
]);
