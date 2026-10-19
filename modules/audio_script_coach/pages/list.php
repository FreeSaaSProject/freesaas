<?php
declare(strict_types=1);

$adminId = max(0, (int)($_GET['admin'] ?? 0));
$day = trim((string)($_GET['day'] ?? ''));
if ($day !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
    $day = '';
}

$result = CoachRunsRepository::list([
    'admin' => $adminId,
    'day' => $day,
    'limit' => 50,
    'offset' => 0,
]);

if (class_exists('AgentHttp', false) && AgentHttp::wantsJson()) {
    AgentHttp::emit([
        'ok' => true,
        'items' => $result['items'],
        'total' => $result['total'],
    ]);
}

$title = t('audio_script_coach.menu.list');
View::render('list', [
    'title' => $title,
    'items' => $result['items'],
    'total' => $result['total'],
    'adminId' => $adminId,
    'day' => $day,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => $title, 'current' => true],
    ]),
], 'layout', 'audio_script_coach');
