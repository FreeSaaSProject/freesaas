<?php
declare(strict_types=1);

$id = (int)(Router::param('id') ?? 0);
$thumb = !empty($GLOBALS['media_serve_thumb']);
$row = MediaAttachmentsRepository::find($id);
if ($row === null) {
    http_response_code(404);
    echo t('media.err.not_found', ['id' => $id]);
    return;
}
if (!MediaAcl::canAccessAttachment($row)) {
    http_response_code(403);
    echo t('media.err.forbidden');
    return;
}

$rel = $thumb
    ? (string)($row['thumb_path'] ?? '')
    : (string)($row['storage_path'] ?? '');
if ($rel === '' && $thumb) {
    $rel = (string)($row['storage_path'] ?? '');
}
if ($rel === '') {
    http_response_code(404);
    echo t('media.err.file_missing');
    return;
}

try {
    $store = MediaStore::make();
    $path = $store->materialize($rel);
} catch (Throwable $e) {
    http_response_code(404);
    echo t('media.err.file_missing');
    return;
}

$mime = (string)($row['mime'] ?? 'image/jpeg');
if ($mime === '') {
    $mime = 'image/jpeg';
}
header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=300');
header('Content-Length: ' . (string)filesize($path));
readfile($path);
if ($store->isTempMaterialized($path) && is_file($path)) {
    @unlink($path);
}
exit;
