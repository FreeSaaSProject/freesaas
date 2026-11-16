<?php
declare(strict_types=1);
$programId = (int)(Router::param('programId') ?? 0);
$userId = Auth::userId();
$access = CourseAccess::canAccessProgram('staff', $userId, $programId);
if (empty($access['ok'])) {
    http_response_code(403);
    echo t('courses.learn.locked');
    return;
}
$program = CourseProgramsRepository::find($programId);
$lessons = CourseLessonsRepository::listForProgram($programId);
$enrollment = $access['enrollment'];
if (AgentHttp::wantsJson()) {
    AgentHttp::emit(['ok' => true, 'program' => $program, 'lessons' => $lessons, 'enrollment' => $enrollment]);
    return;
}
View::render('learn_program', [
    'title' => (string)($program['name'] ?? ''),
    'program' => $program,
    'lessons' => $lessons,
    'enrollment' => $enrollment,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('courses.learn.title'), 'href' => '/courses/learn'],
        ['label' => (string)($program['name'] ?? ''), 'current' => true],
    ]),
], 'layout', 'courses');
