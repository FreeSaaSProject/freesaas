<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    AgentHttp::error('Method not allowed', 405);
    return;
}

$ownerTable = trim((string)($_POST['owner_table'] ?? ''));
$ownerId = (int)($_POST['owner_id'] ?? 0);
$kind = trim((string)($_POST['kind'] ?? 'other'));
$file = $_FILES['file'] ?? null;
if (!is_array($file)) {
    if ($wantsJson) {
        AgentHttp::error(t('media.err.upload_empty'), 400);
        return;
    }
    http_response_code(400);
    echo t('media.err.upload_empty');
    return;
}

try {
    if (!function_exists('imagecreatefromjpeg')) {
        throw new RuntimeException(t('media.err.gd_required'));
    }
    $row = MediaUpload::fromUpload($ownerTable, $ownerId, $kind, $file);
    if ($wantsJson || !empty($_POST['ajax'])) {
        AgentHttp::emit(['ok' => true, 'item' => $row, 'message' => t('media.flash.uploaded')]);
        return;
    }
    $ret = trim((string)($_POST['ret'] ?? ''));
    if ($ret !== '' && strpos($ret, '/') === 0) {
        redirect($ret);
        return;
    }
    redirect('/media?owner_table=' . rawurlencode($ownerTable) . '&owner_id=' . $ownerId);
} catch (Throwable $e) {
    if ($wantsJson || !empty($_POST['ajax'])) {
        AgentHttp::error($e->getMessage(), 400);
        return;
    }
    http_response_code(400);
    echo $e->getMessage();
}
