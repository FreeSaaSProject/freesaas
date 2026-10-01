<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/NewsRepository.php';
require_once __DIR__ . '/lib/UsersRepository.php';
require_once __DIR__ . '/lib/StatusesRepository.php';
require_once __DIR__ . '/lib/SystemRepository.php';
require_once __DIR__ . '/lib/LikesRepository.php';
require_once __DIR__ . '/lib/NoticesRepository.php';
require_once __DIR__ . '/lib/Breadcrumbs.php';

$pages = [
    '/login' => ['public' => true, 'methods' => ['GET', 'POST'], 'file' => 'login.php'],
    '/logout' => ['permission' => 'core.home', 'methods' => ['GET', 'POST'], 'handler' => 'logout'],
    '/' => ['permission' => 'core.home', 'methods' => ['GET'], 'file' => 'home.php'],
    '/modules' => ['permission' => 'core.modules', 'methods' => ['GET', 'POST'], 'file' => 'modules.php'],
    '/migrate' => ['public' => true, 'permission' => 'core.migrate', 'methods' => ['GET', 'POST'], 'file' => 'migrate.php'],
    '/users' => ['permission' => 'core.users', 'methods' => ['GET'], 'file' => 'users.php'],
    '/users/{id}' => ['permission' => 'core.users', 'methods' => ['GET'], 'file' => 'user.php'],
    '/users/{id}/edit' => ['permission' => 'core.users', 'methods' => ['GET', 'POST'], 'file' => 'user_edit.php'],
    '/statuses' => ['permission' => 'core.statuses', 'methods' => ['GET'], 'file' => 'statuses.php'],
    '/statuses/new' => ['permission' => 'core.statuses', 'methods' => ['GET', 'POST'], 'file' => 'status_edit.php'],
    '/statuses/{id}/edit' => ['permission' => 'core.statuses', 'methods' => ['GET', 'POST'], 'file' => 'status_edit.php'],
    '/settings' => ['permission' => 'core.settings', 'methods' => ['GET', 'POST'], 'file' => 'settings.php'],
    '/locale' => ['public' => true, 'methods' => ['GET', 'POST'], 'file' => 'locale.php'],
    '/news' => ['permission' => 'core.news', 'methods' => ['GET'], 'file' => 'news.php'],
    '/news/new' => ['permission' => 'core.news', 'methods' => ['GET', 'POST'], 'file' => 'news_edit.php'],
    '/news/{id}' => ['permission' => 'core.news', 'methods' => ['GET', 'POST'], 'file' => 'news_item.php'],
    '/news/{id}/edit' => ['permission' => 'core.news', 'methods' => ['GET', 'POST'], 'file' => 'news_edit.php'],
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

EventBus::on('core.dashboard.widgets', static function ($widgets) {
    if (!is_array($widgets)) {
        $widgets = [];
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
    $widgets[] = [
        'id' => 'core.status',
        'title' => t('core.widget.platform.title'),
        'body' => $ready
            ? t('core.widget.platform.body', ['users' => $users, 'enabled' => $enabled, 'total' => count($mods)])
            : t('core.widget.platform.missing'),
        'tone' => $ready ? 'ok' : 'warn',
        'href' => '/users',
    ];
    $widgets[] = [
        'id' => 'core.tickets',
        'title' => t('core.widget.tickets.title'),
        'body' => t('core.widget.tickets.body', ['n' => $newsOpen]),
        'tone' => $newsOpen > 0 ? 'warn' : 'ok',
        'href' => '/news?type=2',
    ];
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
