<?php
declare(strict_types=1);

require_once base_path('modules/polls/lib/PollsRepository.php');
require_once __DIR__ . '/lib/PollAnswersCodec.php';
require_once __DIR__ . '/lib/PollWalkEngine.php';
require_once __DIR__ . '/lib/PollInvitesConfig.php';
require_once __DIR__ . '/lib/PollInvitesRepository.php';

$pages = [
    '/poll-invites' => ['permission' => 'poll_invites.view', 'methods' => ['GET', 'POST'], 'file' => 'list.php'],
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

EventBus::on('polls.settings.sections', static function ($payload) {
    if (!is_array($payload) || !Auth::capability('SUPERADMIN_ACCESS')) {
        return $payload;
    }
    $payload['html'] = (string)($payload['html'] ?? '') . View::capture('_settings_section', [
        'cfg' => PollInvitesConfig::formValues(),
        'pollOptions' => class_exists('PollsRepository', false)
            ? PollsRepository::rootNames(true)
            : [],
    ], 'poll_invites');
    return $payload;
});

EventBus::on('polls.settings.save', static function ($payload) {
    if (!is_array($payload) || !Auth::capability('SUPERADMIN_ACCESS')) {
        return $payload;
    }
    $post = is_array($payload['post'] ?? null) ? $payload['post'] : [];
    if (!isset($post['poll_invites_settings_save'])) {
        return $payload;
    }
    PollInvitesConfig::saveFromPost($post);
    return $payload;
});

EventBus::on('calendar.timeline.enrich', static function ($payload) {
    if (!is_array($payload) || !Auth::check() || !Auth::can('poll_invites.edit')) {
        return $payload;
    }
    if (!Database::tableExists('base_poll_invites')) {
        return $payload;
    }
    $days = is_array($payload['days'] ?? null) ? $payload['days'] : [];
    if ($days === []) {
        return $payload;
    }

    $todayStart = (int)strtotime('today');
    $procIds = [];
    $userIds = [];
    $rangeStart = 0;
    $rangeEnd = 0;
    foreach ($days as $day) {
        if (!is_array($day)) {
            continue;
        }
        $tsStart = (int)($day['ts_start'] ?? 0);
        $tsEnd = (int)($day['ts_end'] ?? 0);
        if ($tsStart > 0 && ($rangeStart < 1 || $tsStart < $rangeStart)) {
            $rangeStart = $tsStart;
        }
        if ($tsEnd > 0 && $tsEnd > $rangeEnd) {
            $rangeEnd = $tsEnd;
        }
        foreach (($day['chips'] ?? []) as $chip) {
            if (!is_array($chip) || (string)($chip['kind'] ?? '') !== 'proc') {
                continue;
            }
            $pid = (int)($chip['id'] ?? 0);
            $uid = (int)($chip['id_user'] ?? 0);
            // Paid only (SUMM > 0); skip promo/zero-amount procedures.
            if ($pid > 0 && $uid > 0 && (float)($chip['summ'] ?? 0) > 0) {
                $procIds[$pid] = $pid;
                $userIds[$uid] = $uid;
            }
        }
    }
    $marked = $procIds !== []
        ? PollInvitesRepository::idsMarkedByProc(array_values($procIds))
        : [];
    // One oral mark per client per calendar day (other days do not block).
    $markedClientDay = ($userIds !== [] && $rangeStart > 0 && $rangeEnd > $rangeStart)
        ? PollInvitesRepository::idsMarkedByClientDay(array_values($userIds), $rangeStart, $rangeEnd)
        : [];

    // Stay on calendar after mark — do not use NavContext `ret` (may be /polls/settings).
    $view = (string)($payload['view'] ?? 'day');
    $csrf = class_exists('Csrf', false) ? Csrf::token() : '';

    $renderMarked = static function (int $inviteId): string {
        return '<div class="calendar-info-actions poll-invites-oral">'
            . '<span class="muted">' . e(t('poll_invites.oral.marked')) . '</span> '
            . '<a href="/poll-invites?q=' . $inviteId . '" data-nav-no-ret>#' . $inviteId . '</a>'
            . '</div>';
    };

    foreach ($days as $date => $day) {
        if (!is_array($day) || !isset($day['chips']) || !is_array($day['chips'])) {
            continue;
        }
        $date = (string)$date;
        $backPath = $view === 'week'
            ? '/calendar/week/' . rawurlencode($date)
            : '/calendar/day/' . rawurlencode($date);
        foreach ($day['chips'] as $i => $chip) {
            if (!is_array($chip) || (string)($chip['kind'] ?? '') !== 'proc') {
                continue;
            }
            $pid = (int)($chip['id'] ?? 0);
            $uid = (int)($chip['id_user'] ?? 0);
            if ($pid < 1 || $uid < 1 || (float)($chip['summ'] ?? 0) <= 0) {
                continue;
            }
            $dtStart = (int)($chip['dtstart'] ?? 0);
            if ($dtStart > 0 && $dtStart < $todayStart) {
                continue;
            }
            $dayKey = $uid . '|' . ($dtStart > 0 ? date('Y-m-d', $dtStart) : $date);
            $inviteId = 0;
            if (isset($marked[$pid])) {
                $inviteId = (int)$marked[$pid];
            } elseif (isset($markedClientDay[$dayKey])) {
                $inviteId = (int)$markedClientDay[$dayKey];
            }
            if ($inviteId > 0) {
                $days[$date]['chips'][$i]['actions_html'] = $renderMarked($inviteId);
                $cls = trim((string)($chip['class'] ?? ''));
                $days[$date]['chips'][$i]['class'] = trim($cls . ' has-oral-mark');
                continue;
            }
            $days[$date]['chips'][$i]['actions_html'] = '<div class="calendar-info-actions poll-invites-oral">'
                . '<form method="post" action="/poll-invites/oral-asked" class="inline-form" data-nav-no-ret>'
                . '<input type="hidden" name="csrf" value="' . e($csrf) . '">'
                . '<input type="hidden" name="id_proc" value="' . $pid . '">'
                . '<input type="hidden" name="oral_ret" value="' . e($backPath) . '">'
                . '<button type="submit" class="btn-secondary btn-sm">'
                . e(t('poll_invites.oral.btn'))
                . '</button>'
                . '</form>'
                . '</div>';
        }
    }

    $payload['days'] = $days;
    return $payload;
});
