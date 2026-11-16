<?php
declare(strict_types=1);
$items = CourseEnrollmentsRepository::list([
    'program_id' => (int)($_GET['program_id'] ?? 0),
    'subject_type' => (string)($_GET['subject_type'] ?? ''),
    'subject_id' => (int)($_GET['subject_id'] ?? 0),
    'limit' => 200,
]);
$programs = CourseProgramsRepository::listAll(true);
if (AgentHttp::wantsJson()) {
    AgentHttp::emit(['ok' => true, 'items' => $items]);
    return;
}
View::render('enrollments', [
    'title' => t('courses.enrollments.title'),
    'items' => $items,
    'programs' => $programs,
    'breadcrumbs' => Breadcrumbs::withHome([['label' => t('courses.enrollments.title'), 'current' => true]]),
], 'layout', 'courses');
