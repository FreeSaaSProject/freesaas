<?php
declare(strict_types=1);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (class_exists('Csrf', false) && !AgentHttp::wantsJson() && !Csrf::verify()) {
        Csrf::deny('csrf');
    }
    CoursesConfig::saveFromPost($_POST);
    if (AgentHttp::wantsJson()) {
        AgentHttp::emit(['ok' => true]);
        return;
    }
    flash('ok', t('courses.settings.saved'));
    redirect('/courses/settings');
}
if (AgentHttp::wantsJson()) {
    AgentHttp::emit([
        'ok' => true,
        'storage_root' => (string)Config::get('courses', 'storage_root', 'var/course_media'),
        'media_ttl' => CoursesConfig::mediaTtl(),
    ]);
    return;
}
View::render('settings', [
    'title' => t('courses.settings.title'),
    'storage_root' => (string)Config::get('courses', 'storage_root', 'var/course_media'),
    'media_ttl' => CoursesConfig::mediaTtl(),
    'breadcrumbs' => Breadcrumbs::withHome([['label' => t('courses.settings.title'), 'current' => true]]),
], 'layout', 'courses');
