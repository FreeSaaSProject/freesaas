<?php
declare(strict_types=1);
if (class_exists('Csrf', false) && !AgentHttp::wantsJson() && !Csrf::verify()) {
    Csrf::deny('csrf');
}
try {
    $id = CourseEnrollmentsRepository::grant([
        'program_id' => (int)($_POST['program_id'] ?? 0),
        'subject_type' => (string)($_POST['subject_type'] ?? 'client'),
        'subject_id' => (int)($_POST['subject_id'] ?? 0),
        'source' => (string)($_POST['source'] ?? 'manual'),
        'access_days' => (int)($_POST['access_days'] ?? 0),
    ]);
} catch (Throwable $e) {
    if (AgentHttp::wantsJson()) {
        AgentHttp::error($e->getMessage(), 400);
        return;
    }
    flash('err', $e->getMessage());
    redirect('/courses/enrollments');
}
if (AgentHttp::wantsJson()) {
    AgentHttp::emit(['ok' => true, 'id' => $id]);
    return;
}
flash('ok', t('courses.enrollments.granted'));
redirect('/courses/enrollments');
