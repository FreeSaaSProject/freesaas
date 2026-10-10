<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/NewsRepository.php';
require_once __DIR__ . '/lib/NewsChecklistRepository.php';
require_once __DIR__ . '/lib/NewsLogsRepository.php';
require_once __DIR__ . '/lib/UsersRepository.php';
require_once __DIR__ . '/lib/StatusesRepository.php';
require_once __DIR__ . '/lib/SystemRepository.php';
require_once __DIR__ . '/lib/SystemLogo.php';
require_once __DIR__ . '/lib/LikesRepository.php';
require_once __DIR__ . '/lib/NoticesRepository.php';
require_once __DIR__ . '/lib/Breadcrumbs.php';
require_once __DIR__ . '/lib/UserPrefsRepository.php';
require_once __DIR__ . '/lib/DashboardWidgets.php';
require_once __DIR__ . '/lib/MenuOrder.php';
require_once __DIR__ . '/lib/MyTasksHabits.php';
require_once __DIR__ . '/lib/MyTasksHabitsBot.php';
require_once __DIR__ . '/lib/NewsBotPlugin.php';
require_once __DIR__ . '/lib/PasswordRestoreBotPlugin.php';

// Shared rail groups (core.menu.group.*) — product modules register their own keys.
ModuleManager::registerMenuGroup('core.menu.group.desktop', 10, '/');
ModuleManager::registerMenuGroup('core.menu.group.dictionaries', 50, '/catalog');
ModuleManager::registerMenuGroup('core.menu.group.warehouse', 52);
ModuleManager::registerMenuGroup('core.menu.group.accounting', 55, '/payments');
ModuleManager::registerMenuGroup('core.menu.group.finance', 58, '/ledger/moves');
ModuleManager::registerMenuGroup('core.menu.group.team', 60, '/users');
ModuleManager::registerMenuGroup('core.menu.group.system', 70, '/updates');

$pages = [
    '/login' => ['public' => true, 'methods' => ['GET', 'POST'], 'file' => 'login.php'],
    '/logout' => ['permission' => 'core.home', 'methods' => ['GET', 'POST'], 'handler' => 'logout'],
    '/' => ['permission' => 'core.home', 'methods' => ['GET'], 'file' => 'home.php'],
    '/dashboard' => ['permission' => 'core.home', 'methods' => ['GET', 'POST'], 'file' => 'dashboard.php'],
    '/modules' => ['permission' => 'core.modules', 'methods' => ['GET', 'POST'], 'file' => 'modules.php'],
    '/modules/{id}' => ['permission' => 'core.modules', 'methods' => ['GET', 'POST'], 'file' => 'modules_detail.php'],
    '/db' => ['public' => true, 'permission' => 'core.db', 'methods' => ['GET', 'POST'], 'file' => 'db.php'],
    '/users' => ['permission' => 'core.users', 'methods' => ['GET', 'POST'], 'file' => 'users.php'],
    '/users/new' => ['permission' => 'core.users', 'methods' => ['GET', 'POST'], 'file' => 'user_edit.php'],
    '/users/{id}' => ['permission' => 'core.users', 'methods' => ['GET'], 'file' => 'user.php'],
    '/users/{id}/edit' => ['permission' => 'core.users', 'methods' => ['GET', 'POST'], 'file' => 'user_edit.php'],
    '/statuses' => ['permission' => 'core.statuses', 'methods' => ['GET'], 'file' => 'statuses.php'],
    '/statuses/new' => ['permission' => 'core.statuses', 'methods' => ['GET', 'POST'], 'file' => 'status_edit.php'],
    '/statuses/{id}/edit' => ['permission' => 'core.statuses', 'methods' => ['GET', 'POST'], 'file' => 'status_edit.php'],
    '/settings' => ['permission' => 'core.settings', 'methods' => ['GET', 'POST'], 'file' => 'settings.php'],
    '/locale' => ['public' => true, 'methods' => ['GET', 'POST'], 'file' => 'locale.php'],
    '/health' => ['public' => true, 'methods' => ['GET'], 'file' => 'health.php'],
    '/health/detail' => ['permission' => 'core.modules', 'methods' => ['GET'], 'file' => 'health_detail.php'],
    '/agent' => ['public' => true, 'methods' => ['GET'], 'file' => 'agent_api.php'],
    '/agent/bootstrap' => ['public' => true, 'methods' => ['POST'], 'file' => 'agent_api.php'],
    '/agent/system/status' => ['public' => true, 'methods' => ['GET'], 'file' => 'agent_api.php'],
    '/agent/modules' => ['public' => true, 'methods' => ['GET'], 'file' => 'agent_api.php'],
    '/agent/modules/{id}' => ['public' => true, 'methods' => ['GET'], 'file' => 'agent_api.php'],
    '/agent/modules/{id}/enable' => ['public' => true, 'methods' => ['POST'], 'file' => 'agent_api.php'],
    '/agent/modules/{id}/install' => ['public' => true, 'methods' => ['POST'], 'file' => 'agent_api.php'],
    '/agent/modules/{id}/disable' => ['public' => true, 'methods' => ['POST'], 'file' => 'agent_api.php'],
    '/agent/modules/{id}/validate' => ['public' => true, 'methods' => ['GET'], 'file' => 'agent_api.php'],
    '/agent/validate' => ['public' => true, 'methods' => ['GET'], 'file' => 'agent_api.php'],
    '/agent/db' => ['public' => true, 'methods' => ['GET'], 'file' => 'agent_api.php'],
    '/agent/db/migrate' => ['public' => true, 'methods' => ['GET'], 'file' => 'agent_api.php'],
    '/agent/db/migrate/run' => ['public' => true, 'methods' => ['POST'], 'file' => 'agent_api.php'],
    '/agent/db/dump' => ['public' => true, 'methods' => ['POST'], 'file' => 'agent_api.php'],
    '/agent/db/dumps' => ['public' => true, 'methods' => ['GET'], 'file' => 'agent_api.php'],
    '/agent/db/dump/file' => ['public' => true, 'methods' => ['GET'], 'file' => 'agent_api.php'],
    '/agent/db/restore' => ['public' => true, 'methods' => ['POST'], 'file' => 'agent_api.php'],
    '/agent/db/dump/delete' => ['public' => true, 'methods' => ['POST'], 'file' => 'agent_api.php'],
    '/agent/db/check' => ['public' => true, 'methods' => ['POST'], 'file' => 'agent_api.php'],
    '/agent/db/repair' => ['public' => true, 'methods' => ['POST'], 'file' => 'agent_api.php'],
    '/agent/db/optimize' => ['public' => true, 'methods' => ['POST'], 'file' => 'agent_api.php'],
    '/agent/locale' => ['public' => true, 'methods' => ['GET'], 'file' => 'agent_api.php'],
    '/agent/locale/validate' => ['public' => true, 'methods' => ['GET'], 'file' => 'agent_api.php'],
    '/agent/health' => ['public' => true, 'methods' => ['GET'], 'file' => 'agent_api.php'],
    '/agent/i18n' => ['public' => true, 'methods' => ['GET'], 'file' => 'agent_api.php'],
    '/agent/update' => ['public' => true, 'methods' => ['GET'], 'file' => 'agent_api.php'],
    '/agent/update/check' => ['public' => true, 'methods' => ['GET'], 'file' => 'agent_api.php'],
    '/agent/update/apply' => ['public' => true, 'methods' => ['POST'], 'file' => 'agent_api.php'],
    '/news' => ['permission' => 'core.news', 'methods' => ['GET'], 'file' => 'news.php'],
    '/news/new' => ['permission' => 'core.news', 'methods' => ['GET', 'POST'], 'file' => 'news_edit.php'],
    '/news/{id}' => ['permission' => 'core.news', 'methods' => ['GET', 'POST'], 'file' => 'news_item.php'],
    '/news/{id}/edit' => ['permission' => 'core.news', 'methods' => ['GET', 'POST'], 'file' => 'news_edit.php'],
    '/core/jobs/habits-reminders' => [
        'public' => true,
        'methods' => ['POST', 'GET'],
        'file' => 'jobs_habits_reminders.php',
    ],
];

foreach ($pages as $path => $def) {
    $file = $def['file'] ?? null;
    $handlerName = $def['handler'] ?? null;
    $route = [
        'module' => 'core',
        'methods' => $def['methods'] ?? ['GET'],
    ];
    if (!empty($def['public'])) {
        $route['public'] = true;
    }
    if (!empty($def['permission'])) {
        $route['permission'] = $def['permission'];
    }
    if ($handlerName === 'logout') {
        $route['handler'] = static function (): void {
            Auth::logout();
            Auth::startSession();
            redirect('/login');
        };
    } else {
        $route['handler'] = static function () use ($file): void {
            require base_path('modules/core/pages/' . $file);
        };
    }
    ModuleManager::registerRoute($path, $route);
}

EventBus::on('core.dashboard.catalog', static function ($catalog) {
    if (!is_array($catalog)) {
        $catalog = [];
    }
    $catalog[] = [
        'id' => 'core.command',
        'title' => t('core.widget.command.title'),
        'module' => 'core',
        'default_order' => 5,
        'default_on' => true,
    ];
    $catalog[] = [
        'id' => 'core.tickets',
        'title' => t('core.widget.tickets.title'),
        'module' => 'core',
        'default_order' => 30,
        'default_on' => true,
    ];
    if (Auth::can('core.news') && Database::tableExists('base_news')) {
        $feedOrder = 40;
        foreach (NewsRepository::typeTabs() as $tab) {
            $tid = (int)$tab['id'];
            $catalog[] = [
                'id' => 'core.feed.' . $tid,
                'title' => (string)$tab['label'],
                'module' => 'core',
                'default_order' => $feedOrder,
                'default_on' => true,
                'settings' => DashboardWidgets::limitSettingSchema(8, 1, 40),
            ];
            $feedOrder += 5;
        }
    }
    $catalog[] = [
        'id' => 'core.status',
        'title' => t('core.widget.platform.title'),
        'module' => 'core',
        'default_order' => 90,
        'default_on' => false,
    ];
    return $catalog;
});

EventBus::on('core.dashboard.widgets', static function ($widgets) {
    if (!is_array($widgets)) {
        $widgets = [];
    }

    if (DashboardWidgets::isEnabled('core.command')) {
        $commands = [];
        foreach (ModuleManager::menuItems() as $item) {
            $commands[] = [
                'path' => $item['path'],
                'title' => $item['title'],
                'group' => $item['group'],
            ];
        }
        $widgets[] = [
            'id' => 'core.command',
            'title' => '',
            'html' => View::capture('_widget_command', ['commands' => $commands], 'core'),
            'tone' => 'neutral',
            'size' => 'lg',
        ];
    }

    $users = 0;
    $ready = Database::tableExists('base_users');
    if ($ready) {
        try {
            $users = (int)Database::pdo()->query("SELECT COUNT(*) FROM base_users WHERE enabled='1'")->fetchColumn();
        } catch (Throwable $e) {
            $users = 0;
        }
    }
    $newsOpen = 0;
    if (Database::tableExists('base_news')) {
        try {
            $newsOpen = (int)Database::pdo()->query(
                'SELECT COUNT(*) FROM base_news WHERE DISABLED=0 AND HAS_CHILDREN=0 AND TYPE=2 AND STATUS=1'
            )->fetchColumn();
        } catch (Throwable $e) {
            $newsOpen = 0;
        }
    }
    $mods = ModuleManager::all();
    $enabled = 0;
    foreach ($mods as $m) {
        if (!empty($m['_enabled'])) {
            $enabled++;
        }
    }
    if (DashboardWidgets::isEnabled('core.status')) {
        $widgets[] = [
            'id' => 'core.status',
            'title' => t('core.widget.platform.title'),
            'body' => $ready
                ? t('core.widget.platform.body', ['users' => $users, 'enabled' => $enabled, 'total' => count($mods)])
                : t('core.widget.platform.missing'),
            'tone' => $ready ? 'ok' : 'warn',
            'href' => '/users',
            'size' => 'sm',
        ];
    }
    if (DashboardWidgets::isEnabled('core.tickets')) {
        $widgets[] = [
            'id' => 'core.tickets',
            'title' => t('core.widget.tickets.title'),
            'body' => t('core.widget.tickets.body', ['n' => $newsOpen]),
            'tone' => $newsOpen > 0 ? 'warn' : 'ok',
            'href' => '/news?type=2',
            'size' => 'sm',
        ];
    }

    if (Auth::can('core.news') && Database::tableExists('base_news')) {
        $uid = Auth::userId();
        $statuses = NewsRepository::statuses();
        foreach (NewsRepository::typeTabs() as $tab) {
            $tid = (int)$tab['id'];
            $wid = 'core.feed.' . $tid;
            if (!DashboardWidgets::isEnabled($wid)) {
                continue;
            }
            $limit = (int)DashboardWidgets::setting($wid, 'limit', 8);
            if ($limit < 1) {
                $limit = 8;
            }
            if (class_exists('StageTiming', false)) {
                StageTiming::mark('Home: feed type ' . $tid);
            }
            $result = NewsRepository::list([
                'type' => $tid,
                'limit' => $limit,
                'user_id' => $uid,
            ]);
            $ids = [];
            foreach ($result['items'] as $n) {
                $ids[] = (int)$n['ID'];
            }
            $html = View::capture('_widget_feed', [
                'type' => $tid,
                'label' => (string)$tab['label'],
                'add' => $tab['add'] ?? null,
                'addType' => $tab['addType'] ?? null,
                'items' => $result['items'],
                'statuses' => $statuses,
                'commentCounts' => NewsRepository::commentCounts($ids),
                'viewerCounts' => NewsRepository::viewerCounts($ids),
            ], 'core');
            $widgets[] = [
                'id' => $wid,
                'title' => (string)$tab['label'],
                'html' => $html,
                'tone' => 'neutral',
                'size' => 'md',
            ];
        }
    }
    return $widgets;
});

EventBus::on('core.menu.collect', static function ($items) {
    if (!is_array($items)) {
        $items = [];
    }
    foreach (ModuleManager::menuItems() as $row) {
        $items[] = $row;
    }
    return $items;
});

EventBus::on('core.notices.collect', static function ($notices) {
    if (!is_array($notices)) {
        $notices = [];
    }
    if (!Auth::check()) {
        return $notices;
    }
    foreach (NoticesRepository::newsNotices(Auth::userId()) as $n) {
        $notices[] = $n;
    }
    return $notices;
});

EventBus::on('comments.form.ticket_controls', static function ($payload) {
    if (!is_array($payload)) {
        return $payload;
    }
    if (empty($payload['is_ticket']) || empty($payload['can_manage'])) {
        return $payload;
    }
    $row = $payload['row'] ?? [];
    if (!is_array($row)) {
        $row = [];
    }
    $statuses = is_array($payload['statuses'] ?? null) ? $payload['statuses'] : [];
    $status = is_array($payload['status'] ?? null) ? $payload['status'] : null;
    $respIds = is_array($payload['resp_ids'] ?? null) ? $payload['resp_ids'] : [];
    $staff = is_array($payload['staff'] ?? null) ? $payload['staff'] : [];
    $contractLocked = !empty($payload['contract_locked']);
    $hasDeadline = !empty($payload['has_deadline']);
    $deadlineVal = $hasDeadline ? e(date('Y-m-d', (int)$row['DT_DEADLINE'])) : '';
    $curatorSelected = (int)($row['ID_CURATOR'] ?? 0);

    $statusOpts = '<option value="-1">' . te('core.ticket.no_change', ['status' => (string)($status['NAME'] ?? (string)($row['STATUS'] ?? ''))]) . '</option>';
    foreach ($statuses as $sid => $st) {
        $statusOpts .= '<option value="' . (int)$sid . '">' . e((string)$st['NAME']) . '</option>';
    }

    $curatorOpts = '<option value="0">—</option>';
    foreach ($staff as $u) {
        $sel = $curatorSelected === (int)$u['id'] ? ' selected' : '';
        $curatorOpts .= '<option value="' . (int)$u['id'] . '"' . $sel . '>'
            . e(($u['name'] ?: $u['login'])) . '</option>';
    }

    $respChecks = '';
    foreach ($staff as $u) {
        $checked = in_array((int)$u['id'], $respIds, true) ? ' checked' : '';
        $disabled = $contractLocked ? ' disabled' : '';
        $respChecks .= '<label class="check">'
            . '<input type="checkbox" name="users_resp[]" value="' . (int)$u['id'] . '"'
            . $checked . $disabled . '>'
            . UsersRepository::badgeHtml((int)$u['id'], false)
            . '<span>' . e(($u['name'] ?: $u['login'])) . '</span>'
            . '</label>';
    }

    $lockNote = $contractLocked
        ? '<p class="flash flash-warn" style="margin:0">' . te('core.ticket.contract_locked') . '</p>'
        : '';
    $curatorLabel = te('core.ticket.curator') . ($contractLocked ? ' <i class="muted">' . te('core.ticket.contract_tag') . '</i>' : '');
    $respLegend = te('core.ticket.responsible') . ($contractLocked ? ' ' . te('core.ticket.contract_tag') : '');
    $lblStatus = te('core.ticket.status');
    $lblDeadline = te('core.ticket.deadline');
    $fieldsetDisabled = $contractLocked ? ' disabled' : '';
    $selectDisabled = $contractLocked ? ' disabled' : '';
    $respPresent = $contractLocked ? '0' : '1';

    $payload['html'] = <<<HTML
<div class="ticket-controls">
  {$lockNote}
  <label>{$lblStatus}
    <select name="new_status">{$statusOpts}</select>
  </label>
  <label>{$lblDeadline}
    <input type="date" name="ticket_deadline" value="{$deadlineVal}">
  </label>
  <label>{$curatorLabel}
    <select name="id_curator"{$selectDisabled}>{$curatorOpts}</select>
  </label>
  <fieldset class="resp-pick"{$fieldsetDisabled}>
    <legend>{$respLegend}</legend>
    <input type="hidden" name="users_resp_present" value="{$respPresent}">
    <div class="resp-checks">{$respChecks}</div>
  </fieldset>
</div>
HTML;
    return $payload;
});

EventBus::on('comments.prepare_add', static function ($payload) {
    if (!is_array($payload) || !empty($payload['error'])) {
        return $payload;
    }
    $row = $payload['row'] ?? null;
    $post = $payload['post'] ?? null;
    $uid = (int)($payload['uid'] ?? 0);
    $id = (int)($payload['id'] ?? 0);
    if (!is_array($row) || !is_array($post) || $uid < 1 || $id < 1) {
        return $payload;
    }
    if ((int)$row['TYPE'] !== 2) {
        return $payload;
    }

    $text = (string)($payload['text'] ?? '');
    $statusUp = !empty($payload['status_up']);
    $updates = [];
    $changeParts = [];
    $userText = trim($text);

    if (NewsRepository::canManageTicket($row, $uid)) {
        $newStatus = isset($post['new_status']) ? (int)$post['new_status'] : -1;
        if ($newStatus >= 0 && $newStatus !== (int)$row['STATUS']) {
            $updates['STATUS'] = $newStatus;
            $statusUp = true;
            $label = NewsRepository::statuses()[$newStatus]['NAME'] ?? ('#' . $newStatus);
            $changeParts[] = 'Статус → ' . $label;
        }
        $dl = trim((string)($post['ticket_deadline'] ?? ''));
        if ($dl !== '') {
            $ts = strtotime($dl . ' 23:59:59');
            if ($ts && $ts !== (int)$row['DT_DEADLINE']) {
                $updates['DT_DEADLINE'] = $ts;
                $statusUp = true;
                $changeParts[] = 'ДЛ → ' . date('d.m.Y', $ts);
            }
        }
        if (!empty($post['users_resp_present'])) {
            $respIdsPost = [];
            if (isset($post['users_resp']) && is_array($post['users_resp'])) {
                $respIdsPost = array_values(array_filter(array_map('intval', $post['users_resp'])));
            }
            $respStr = $respIdsPost ? '#' . implode('#,#', $respIdsPost) . '#' : '';
            if ($respStr !== (string)$row['USERS_RESP']) {
                $updates['USERS_RESP'] = $respStr;
                $statusUp = true;
                $olLabel = $respIdsPost ? UsersRepository::acronymsList($respIdsPost) : 'откл.';
                $changeParts[] = 'ОЛ → ' . $olLabel;
            }
        }
        $curator = isset($post['id_curator']) ? (int)$post['id_curator'] : -1;
        if ($curator >= 0 && $curator !== (int)$row['ID_CURATOR']) {
            $updates['ID_CURATOR'] = $curator;
            $statusUp = true;
            $curLabel = $curator > 0 ? UsersRepository::acronymsList([$curator]) : 'откл.';
            if ($curLabel === '') {
                $curLabel = 'откл.';
            }
            $changeParts[] = 'Куратор → ' . $curLabel;
        }
    }

    if ($changeParts) {
        $summary = implode('; ', $changeParts);
        $text = $userText === '' ? $summary : ($summary . "\n" . $userText);
    }
    if ($updates) {
        NewsRepository::update($id, $updates, $uid);
        $payload['row'] = NewsRepository::find($id) ?: $row;
    }
    $payload['text'] = $text;
    $payload['status_up'] = $statusUp;
    return $payload;
});

NewsBotPlugin::register();
PasswordRestoreBotPlugin::register();
