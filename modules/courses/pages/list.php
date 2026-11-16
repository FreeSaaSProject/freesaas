<?php
declare(strict_types=1);
$items = CourseProgramsRepository::listAll();
if (AgentHttp::wantsJson()) {
    AgentHttp::emit(['ok' => true, 'items' => $items]);
    return;
}
View::render('list', [
    'title' => t('courses.list.title'),
    'items' => $items,
    'breadcrumbs' => Breadcrumbs::withHome([['label' => t('courses.list.title'), 'current' => true]]),
], 'layout', 'courses');
