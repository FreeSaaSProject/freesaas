<?php
declare(strict_types=1);

require_once base_path('modules/polls/lib/PollsRepository.php');
require_once __DIR__ . '/lib/PollAnswersCodec.php';
require_once __DIR__ . '/lib/PollWalkEngine.php';
require_once __DIR__ . '/lib/PollInvitesConfig.php';
require_once __DIR__ . '/lib/PollInvitesRepository.php';
require_once __DIR__ . '/lib/PollInvitesBiContrib.php';

Assets::register('poll_invites', [
    'css' => ['poll_invites.css'],
]);

EventBus::on('bi.datasets', [PollInvitesBiContrib::class, 'onDatasets']);

$pages = [
    '/poll-invites' => ['permission' => 'poll_invites.view', 'methods' => ['GET', 'POST'], 'file' => 'list.php'],
    '/poll-invites/unviewed' => [
        'permission' => 'poll_invites.view',
        'methods' => ['GET', 'POST'],
        'file' => 'unviewed.php',
    ],
    '/poll-invites/new' => [
        'permission' => 'poll_invites.edit',
        'capability' => 'SUPERADMIN_ACCESS',
        'methods' => ['GET', 'POST'],
        'file' => 'edit.php',
    ],
    '/poll-invites/oral-asked' => [
        'permission' => 'poll_invites.edit',
        'methods' => ['POST'],
        'file' => 'oral_asked.php',
    ],
    '/poll-invites/{id}/edit' => [
        'permission' => 'poll_invites.edit',
        'capability' => 'SUPERADMIN_ACCESS',
        'methods' => ['GET', 'POST'],
        'file' => 'edit.php',
    ],
    '/poll-invites/{id}/fill' => [
        'permission' => 'poll_invites.edit',
        'capability' => 'SUPERADMIN_ACCESS',
        'methods' => ['GET', 'POST'],
        'file' => 'fill.php',
    ],
];

foreach ($pages as $path => $def) {
    $file = (string)$def['file'];
    $route = [
        'module' => 'poll_invites',
        'methods' => $def['methods'],
        'permission' => $def['permission'],
        'handler' => static function () use ($file): void {
            require base_path('modules/poll_invites/pages/' . $file);
        },
    ];
    if (!empty($def['capability'])) {
        $route['capability'] = $def['capability'];
    }
    ModuleManager::registerRoute($path, $route);
}

// Short card URL → edit form (scores + responsible staff).
ModuleManager::registerRoute('/poll-invites/{id}', [
    'module' => 'poll_invites',
    'methods' => ['GET'],
    'permission' => 'poll_invites.edit',
    'capability' => 'SUPERADMIN_ACCESS',
    'handler' => static function (): void {
        $id = (int)(Router::param('id') ?? 0);
        if ($id < 1) {
            redirect('/poll-invites');
            return;
        }
        redirect('/poll-invites/' . $id . '/edit');
    },
]);

BulkActions::register('poll_invites', 'delete', [
    'label' => 'core.bulk.delete',
    'permission' => 'poll_invites.delete',
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

// Calendar “review asked” UI + oral settings live in poll_speech when enabled.
// Fallback hub section on /polls/settings only if poll_speech is off.
EventBus::on('polls.settings.sections', static function ($payload) {
    if (!is_array($payload) || !Auth::capability('SUPERADMIN_ACCESS')) {
        return $payload;
    }
    if (ModuleManager::isEnabled('poll_speech')) {
        return $payload;
    }
    $pollOptions = class_exists('PollsRepository', false) ? PollsRepository::rootNames(true) : [];
    $payload['html'] = (string)($payload['html'] ?? '') . View::capture('_settings_section', [
        'cfg' => PollInvitesConfig::formValues(),
        'pollOptions' => $pollOptions,
    ], 'poll_invites');
    return $payload;
});

EventBus::on('polls.settings.save', static function ($payload) {
    if (!is_array($payload) || !Auth::capability('SUPERADMIN_ACCESS')) {
        return $payload;
    }
    if (ModuleManager::isEnabled('poll_speech')) {
        return $payload;
    }
    $post = is_array($payload['post'] ?? null) ? $payload['post'] : [];
    if (!isset($post['poll_invites_settings_save'])) {
        return $payload;
    }
    PollInvitesConfig::saveFromPost($post);
    return $payload;
});
