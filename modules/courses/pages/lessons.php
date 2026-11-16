<?php
declare(strict_types=1);
$programId = (int)(Router::param('id') ?? 0);
$program = CourseProgramsRepository::find($programId);
if ($program === null) {
    http_response_code(404);
    echo 'Not found';
    return;
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (class_exists('Csrf', false) && !AgentHttp::wantsJson() && !Csrf::verify()) {
        Csrf::deny('csrf');
    }
    $action = (string)($_POST['action'] ?? 'save');
    if ($action === 'delete') {
        CourseLessonsRepository::delete((int)($_POST['lesson_id'] ?? 0));
        if (AgentHttp::wantsJson()) {
            AgentHttp::emit(['ok' => true, 'deleted' => true]);
            return;
        }
        flash('ok', t('courses.flash.deleted'));
        redirect('/courses/' . $programId . '/lessons');
    }
    $lessonId = CourseLessonsRepository::save((int)($_POST['lesson_id'] ?? 0), [
        'program_id' => $programId,
        'module_id' => (int)($_POST['module_id'] ?? 0),
        'name' => (string)($_POST['name'] ?? ''),
        'body' => (string)($_POST['body'] ?? ''),
        'sort' => (int)($_POST['sort'] ?? 0),
        'drip_days' => (int)($_POST['drip_days'] ?? 0),
        'enabled' => !empty($_POST['enabled']) || !isset($_POST['enabled']),
    ]);
    if (AgentHttp::wantsJson()) {
        AgentHttp::emit(['ok' => true, 'id' => $lessonId]);
        return;
    }
    flash('ok', t('courses.flash.saved'));
    redirect('/courses/' . $programId . '/lessons');
}
$lessons = CourseLessonsRepository::listForProgram($programId);
if (AgentHttp::wantsJson()) {
    AgentHttp::emit(['ok' => true, 'program' => $program, 'lessons' => $lessons]);
    return;
}
View::render('lessons', [
    'title' => t('courses.lessons.title'),
    'program' => $program,
    'lessons' => $lessons,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('courses.list.title'), 'href' => '/courses'],
        ['label' => (string)$program['name'], 'href' => '/courses/' . $programId . '/edit'],
        ['label' => t('courses.lessons.title'), 'current' => true],
    ]),
], 'layout', 'courses');
