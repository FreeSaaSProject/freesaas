<?php
declare(strict_types=1);
$assetId = (int)(Router::param('assetId') ?? 0);
$exp = (int)($_GET['exp'] ?? 0);
$sig = (string)($_GET['sig'] ?? '');
$st = (string)($_GET['st'] ?? '');
$sid = (int)($_GET['sid'] ?? 0);
$asset = CourseMedia::findAsset($assetId);
if ($asset === null || !CourseMedia::verify($assetId, $st, $sid, $exp, $sig)) {
    http_response_code(403);
    echo t('courses.media.forbidden');
    return;
}
$lesson = CourseLessonsRepository::find((int)$asset['lesson_id']);
if ($lesson === null) {
    http_response_code(403);
    echo t('courses.media.forbidden');
    return;
}
$access = CourseAccess::canAccessProgram($st, $sid, (int)$lesson['program_id']);
if (empty($access['ok']) || !CourseAccess::canAccessLesson($access['enrollment'], $lesson)) {
    http_response_code(403);
    echo t('courses.media.forbidden');
    return;
}
$path = CourseMedia::absolutePath((string)$asset['storage_path']);
if (!is_file($path)) {
    http_response_code(404);
    echo 'Not found';
    return;
}
CourseMedia::logHit($assetId, $st, $sid);
$mime = (string)($asset['mime'] ?? 'application/octet-stream');
header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($path));
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
readfile($path);
