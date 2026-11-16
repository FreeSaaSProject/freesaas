<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/CoursesConfig.php';
require_once __DIR__ . '/lib/CourseProgramsRepository.php';
require_once __DIR__ . '/lib/CourseLessonsRepository.php';
require_once __DIR__ . '/lib/CourseEnrollmentsRepository.php';
require_once __DIR__ . '/lib/CourseAccess.php';
require_once __DIR__ . '/lib/CourseMedia.php';
require_once __DIR__ . '/lib/CourseEnrollService.php';

Assets::register('courses', ['css' => ['courses.css']]);
ModuleManager::registerMenuGroup('courses.menu.group', 38);

$admin = [
    '/courses' => ['permission' => 'courses.view', 'methods' => ['GET'], 'file' => 'list.php'],
    '/courses/new' => ['permission' => 'courses.edit', 'methods' => ['GET', 'POST'], 'file' => 'edit.php'],
    '/courses/{id}/edit' => ['permission' => 'courses.edit', 'methods' => ['GET', 'POST'], 'file' => 'edit.php'],
    '/courses/{id}/lessons' => ['permission' => 'courses.view', 'methods' => ['GET', 'POST'], 'file' => 'lessons.php'],
    '/courses/enrollments' => ['permission' => 'courses.enroll', 'methods' => ['GET'], 'file' => 'enrollments.php'],
    '/courses/enrollments/grant' => ['permission' => 'courses.enroll', 'methods' => ['POST'], 'file' => 'enroll_grant.php'],
    '/courses/status' => ['permission' => 'courses.view', 'methods' => ['GET'], 'file' => 'status.php'],
    '/courses/learn' => ['permission' => 'courses.learn', 'methods' => ['GET'], 'file' => 'learn_home.php'],
    '/courses/learn/{programId}' => ['permission' => 'courses.learn', 'methods' => ['GET'], 'file' => 'learn_program.php'],
    '/courses/learn/{programId}/lesson/{lessonId}' => ['permission' => 'courses.learn', 'methods' => ['GET'], 'file' => 'learn_lesson.php'],
    '/courses/settings' => [
        'permission' => 'courses.edit',
        'capability' => 'SUPERADMIN_ACCESS',
        'methods' => ['GET', 'POST'],
        'file' => 'settings.php',
    ],
];
foreach ($admin as $path => $def) {
    $file = (string)$def['file'];
    $opts = [
        'module' => 'courses',
        'methods' => $def['methods'],
        'permission' => $def['permission'],
        'handler' => static function () use ($file): void {
            require base_path('modules/courses/pages/' . $file);
        },
    ];
    if (!empty($def['capability'])) {
        $opts['capability'] = $def['capability'];
    }
    ModuleManager::registerRoute($path, $opts);
}

ModuleManager::registerRoute('/courses/media/{assetId}', [
    'module' => 'courses',
    'methods' => ['GET'],
    'public' => true,
    'handler' => static function (): void {
        require base_path('modules/courses/pages/media.php');
    },
]);

EventBus::on('acquiring.payment.succeeded', static function (array $payload): array {
    $orderId = (int)($payload['order_id'] ?? 0);
    $clientId = (int)($payload['client_id'] ?? 0);
    if ($orderId > 0 && $clientId > 0) {
        CourseEnrollService::enrollFromOrder($orderId, $clientId);
    }
    return $payload;
});

EventBus::on('client_portal.sections', static function (array $payload): array {
    $sections = is_array($payload['sections'] ?? null) ? $payload['sections'] : [];
    $sections[] = [
        'id' => 'courses',
        'module' => 'courses',
        'title' => t('courses.section.title'),
        'weight' => 40,
        'nav' => true,
        'home_card' => true,
    ];
    $payload['sections'] = $sections;
    return $payload;
});

EventBus::on('client_portal.home', static function (array $payload): array {
    $cards = is_array($payload['cards'] ?? null) ? $payload['cards'] : [];
    foreach ($cards as $i => $card) {
        if (!is_array($card) || (string)($card['id'] ?? '') !== 'courses') {
            continue;
        }
        $cards[$i]['html'] = '<p class="muted">' . e(t('courses.section.home_card')) . '</p>';
    }
    $payload['cards'] = $cards;
    return $payload;
});

EventBus::on('client_portal.render', static function (array $payload): array {
    if ((string)($payload['section'] ?? '') !== 'courses') {
        return $payload;
    }
    $clientId = (int)($payload['client_id'] ?? 0);
    $rows = CourseEnrollmentsRepository::list([
        'subject_type' => 'client',
        'subject_id' => $clientId,
        'limit' => 50,
    ]);
    ob_start();
    require base_path('modules/courses/views/portal_courses.php');
    $payload['html'] = (string)ob_get_clean();
    return $payload;
});
// $clientId is used inside portal_courses.php via extract/local scope from require
