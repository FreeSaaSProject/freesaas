<?php
declare(strict_types=1);
$userId = Auth::userId();
$items = CourseEnrollmentsRepository::list([
    'subject_type' => 'staff',
    'subject_id' => $userId,
    'limit' => 100,
]);
if (AgentHttp::wantsJson()) {
    AgentHttp::emit(['ok' => true, 'items' => $items]);
    return;
}
View::render('learn_home', [
    'title' => t('courses.learn.title'),
    'items' => $items,
    'breadcrumbs' => Breadcrumbs::withHome([['label' => t('courses.learn.title'), 'current' => true]]),
], 'layout', 'courses');
