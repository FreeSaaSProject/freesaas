<?php
declare(strict_types=1);
$id = (int)(Router::param('id') ?? 0);
$row = $id > 0 ? CourseProgramsRepository::find($id) : [
    'id' => 0, 'name' => '', 'descr' => '', 'audience' => 'clients',
    'product_id' => 0, 'access_days' => 0, 'enabled' => 1,
];
if ($id > 0 && $row === null) {
    http_response_code(404);
    echo 'Not found';
    return;
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (class_exists('Csrf', false) && !AgentHttp::wantsJson() && !Csrf::verify()) {
        Csrf::deny('csrf');
    }
    $id = CourseProgramsRepository::save($id, [
        'name' => (string)($_POST['name'] ?? ''),
        'descr' => (string)($_POST['descr'] ?? ''),
        'audience' => (string)($_POST['audience'] ?? 'clients'),
        'product_id' => (int)($_POST['product_id'] ?? 0),
        'access_days' => (int)($_POST['access_days'] ?? 0),
        'enabled' => !empty($_POST['enabled']),
    ]);
    if (AgentHttp::wantsJson()) {
        AgentHttp::emit(['ok' => true, 'id' => $id]);
        return;
    }
    flash('ok', t('courses.flash.saved'));
    redirect('/courses/' . $id . '/edit');
}
if (AgentHttp::wantsJson()) {
    AgentHttp::emit(['ok' => true, 'item' => $row]);
    return;
}
$title = $id > 0 ? t('courses.edit.title') : t('courses.edit.title_new');
View::render('edit', [
    'title' => $title,
    'row' => $row,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('courses.list.title'), 'href' => '/courses'],
        ['label' => $title, 'current' => true],
    ]),
], 'layout', 'courses');
