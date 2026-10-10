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

EventBus::on('core.news_item.meta', static function (array $payload): array {
    if (!GcalConfig::enabled()) {
        return $payload;
    }
    $row = $payload['row'] ?? null;
    if (!is_array($row) || (int)($row['TYPE'] ?? 0) !== 2 || !empty($payload['is_folder'])) {
        return $payload;
    }
    $uid = (int)($payload['uid'] ?? Auth::userId());
    if ($uid <= 0) {
        return $payload;
    }
    $nid = (int)$row['ID'];
    if (GcalSyncService::userHasLink($nid, $uid)) {
        $html = (string)($payload['html'] ?? '');
        $html .= ' <span class="chip chip-gcal" title="' . te('gcal.chip.linked') . '">'
            . te('gcal.chip.linked') . '</span>';
        $payload['html'] = $html;
    }
    if (!GcalConfig::appConfigured()) {
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
