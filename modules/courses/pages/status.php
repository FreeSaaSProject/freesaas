<?php
declare(strict_types=1);
$payload = [
    'ok' => true,
    'programs' => count(CourseProgramsRepository::listAll()),
    'storage_root' => CoursesConfig::storageRoot(),
    'media_ttl' => CoursesConfig::mediaTtl(),
];
if (AgentHttp::wantsJson()) {
    AgentHttp::emit($payload);
    return;
}
View::render('status', [
    'title' => t('courses.status.title'),
    'payload' => $payload,
    'breadcrumbs' => Breadcrumbs::withHome([['label' => t('courses.status.title'), 'current' => true]]),
], 'layout', 'courses');
