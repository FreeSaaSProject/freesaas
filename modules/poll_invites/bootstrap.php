<?php
declare(strict_types=1);

require_once base_path('modules/polls/lib/PollsRepository.php');
require_once __DIR__ . '/lib/PollAnswersCodec.php';
require_once __DIR__ . '/lib/PollWalkEngine.php';
require_once __DIR__ . '/lib/PollInvitesRepository.php';

$pages = [
    '/poll-invites' => ['permission' => 'poll_invites.view', 'methods' => ['GET', 'POST'], 'file' => 'list.php'],
    '/poll-invites/new' => ['permission' => 'poll_invites.edit', 'methods' => ['GET', 'POST'], 'file' => 'edit.php'],
    '/poll-invites/{id}/edit' => ['permission' => 'poll_invites.edit', 'methods' => ['GET', 'POST'], 'file' => 'edit.php'],
    '/poll-invites/{id}/fill' => ['permission' => 'poll_invites.edit', 'methods' => ['GET', 'POST'], 'file' => 'fill.php'],
];

foreach ($pages as $path => $def) {
    $file = (string)$def['file'];
    ModuleManager::registerRoute($path, [
        'module' => 'poll_invites',
        'methods' => $def['methods'],
        'permission' => $def['permission'],
        'handler' => static function () use ($file): void {
            require base_path('modules/poll_invites/pages/' . $file);
        },
    ]);
}

BulkActions::register('poll_invites', 'delete', [
    'label' => 'core.bulk.delete',
    'permission' => 'poll_invites.edit',
    'confirm' => 'core.bulk.confirm_delete',
    'run' => static function (array $ids, array $params): array {
        return ['done' => PollInvitesRepository::deleteByIds($ids), 'skipped' => []];
    },
]);

EventBus::on('core.notices.collect', static function ($notices) {
    if (!is_array($notices)) {
        $notices = [];
    }
    if (!Auth::check()) {
        return $notices;
    }
    foreach (PollInvitesRepository::noticesForUser(Auth::userId()) as $n) {
        $notices[] = $n;
    }
    return $notices;
});
