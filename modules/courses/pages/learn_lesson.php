<?php
declare(strict_types=1);
$programId = (int)(Router::param('programId') ?? 0);
$lessonId = (int)(Router::param('lessonId') ?? 0);
$userId = Auth::userId();
$access = CourseAccess::canAccessProgram('staff', $userId, $programId);
$lesson = CourseLessonsRepository::find($lessonId);
if (empty($access['ok']) || $lesson === null || (int)$lesson['program_id'] !== $programId
    || !CourseAccess::canAccessLesson($access['enrollment'], $lesson)) {
    http_response_code(403);
    echo t('courses.learn.locked');
    return;
}
$watermark = CourseAccess::watermarkLabel('staff', $userId);
$assets = CourseMedia::listForLesson($lessonId);
$assetUrls = [];
foreach ($assets as $a) {
    $assetUrls[(int)$a['id']] = CourseMedia::url((int)$a['id'], 'staff', $userId);
}
if (AgentHttp::wantsJson()) {
    AgentHttp::emit(['ok' => true, 'lesson' => $lesson, 'assets' => $assets, 'watermark' => $watermark]);
    return;
}
View::render('learn_lesson', [
    'title' => (string)$lesson['name'],
    'lesson' => $lesson,
    'watermark' => $watermark,
    'assets' => $assets,
    'asset_urls' => $assetUrls,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('courses.learn.title'), 'href' => '/courses/learn'],
        ['label' => '#' . $programId, 'href' => '/courses/learn/' . $programId],
        ['label' => (string)$lesson['name'], 'current' => true],
    ]),
], 'layout', 'courses');
