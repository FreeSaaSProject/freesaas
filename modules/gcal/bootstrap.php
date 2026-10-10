<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/GcalConfig.php';
require_once __DIR__ . '/lib/GcalSyncRepository.php';
require_once __DIR__ . '/lib/GcalOAuth.php';
require_once __DIR__ . '/lib/GcalApi.php';
require_once __DIR__ . '/lib/GcalSyncService.php';
require_once __DIR__ . '/lib/GcalBotPlugin.php';

GcalBotPlugin::register();

ModuleManager::registerRoute('/gcal/settings', [
    'module' => 'gcal',
    'methods' => ['GET', 'POST'],
    'permission' => 'gcal.manage',
    'capability' => 'SUPERADMIN_ACCESS',
    'handler' => static function (): void {
        require base_path('modules/gcal/pages/settings.php');
    },
]);

ModuleManager::registerRoute('/gcal/oauth', [
    'module' => 'gcal',
    'methods' => ['GET'],
    'public' => true,
    'handler' => static function (): void {
        require base_path('modules/gcal/pages/oauth.php');
    },
]);

ModuleManager::registerRoute('/gcal/oauth/start', [
    'module' => 'gcal',
    'methods' => ['GET'],
    'permission' => 'core.home',
    'handler' => static function (): void {
        require base_path('modules/gcal/pages/oauth_start.php');
    },
]);

ModuleManager::registerRoute('/gcal/work', [
    'module' => 'gcal',
    'methods' => ['GET', 'POST'],
    'permission' => 'gcal.manage',
    'handler' => static function (): void {
        require base_path('modules/gcal/pages/work.php');
    },
]);

ModuleManager::registerRoute('/gcal/enqueue', [
    'module' => 'gcal',
    'methods' => ['POST'],
    'permission' => 'core.news',
    'handler' => static function (): void {
        require base_path('modules/gcal/pages/enqueue.php');
    },
]);

Cron::register('gcal.queue_drain', [
    'module' => 'gcal',
    'label' => 'gcal.cron.queue_drain',
    'every' => 5,
    'handler' => static function (): array {
        if (!GcalConfig::enabled()) {
            return ['ok' => true, 'skipped' => 1, 'message' => 'gcal disabled'];
        }
        $r = GcalSyncService::drain(20, true, 25);
        $fail = (int)($r['fail'] ?? 0);
        return [
            'ok' => $fail === 0,
            'processed' => (int)($r['processed'] ?? 0),
            'message' => 'ok=' . (int)($r['ok'] ?? 0)
                . ' fail=' . $fail
                . ' reconciled=' . (int)($r['reconciled'] ?? 0),
        ];
    },
]);

EventBus::on('core.news.feed_chips', static function ($payload) {
    if (!is_array($payload) || !GcalConfig::enabled()) {
        return $payload;
    }
    $ids = $payload['ids'] ?? [];
    if (!is_array($ids) || $ids === []) {
        return $payload;
    }
    $chips = is_array($payload['chips'] ?? null) ? $payload['chips'] : [];
    $linked = GcalSyncRepository::linkedNewsIds($ids);
    $chip = '<span class="chip chip-gcal" title="' . te('gcal.chip.linked') . '">'
        . te('gcal.chip.linked') . '</span>';
    foreach ($linked as $nid => $_) {
        $prev = (string)($chips[$nid] ?? '');
        $chips[$nid] = $prev . ($prev !== '' ? ' ' : '') . $chip;
    }
    $payload['chips'] = $chips;
    return $payload;
});

EventBus::on('core.news_item.meta', static function (array $payload): array {
    if (!GcalConfig::enabled()) {
        return $payload;
    }
    $row = $payload['row'] ?? null;
    if (!is_array($row) || (int)($row['TYPE'] ?? 0) !== 2 || !empty($payload['is_folder'])) {
        return $payload;
    }
    $uid = (int)($payload['uid'] ?? Auth::userId());
    $nid = (int)$row['ID'];
    $linked = GcalSyncRepository::linkedNewsIds([$nid]);
    if (isset($linked[$nid])) {
        $html = (string)($payload['html'] ?? '');
        $html .= ' <span class="chip chip-gcal" title="' . te('gcal.chip.linked') . '">'
            . te('gcal.chip.linked') . '</span>';
        $payload['html'] = $html;
    }
    if ($uid <= 0 || !GcalConfig::appConfigured()) {
        return $payload;
    }
    $tok = GcalSyncRepository::getToken($uid);
    if (!$tok || trim((string)$tok['REFRESH_TOKEN']) === '' || trim((string)$tok['ACTIVE_CALENDAR_ID']) === '') {
        return $payload;
    }
    $actions = (string)($payload['actions_html'] ?? '');
    $actions .= '<form method="post" action="/gcal/enqueue" class="inline-form">'
        . '<input type="hidden" name="news_id" value="' . $nid . '">'
        . '<input type="hidden" name="reason" value="manual">'
        . '<button type="submit" class="btn-secondary">' . te('gcal.chip.sync') . '</button>'
        . '</form>';
    $payload['actions_html'] = $actions;
    return $payload;
});
