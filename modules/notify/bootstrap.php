<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/NotifyCatalog.php';
require_once __DIR__ . '/lib/NotifyConfig.php';
require_once __DIR__ . '/lib/NotifyRulesRepository.php';
require_once __DIR__ . '/lib/NotifyDeliveriesRepository.php';
require_once __DIR__ . '/lib/NotifyTasksRepository.php';
require_once __DIR__ . '/lib/NotifyTemplate.php';
require_once __DIR__ . '/lib/NotifyTargets.php';
require_once __DIR__ . '/lib/NotifyChannels.php';
require_once __DIR__ . '/lib/NotifyEngine.php';
require_once __DIR__ . '/lib/NotifyInbound.php';
require_once __DIR__ . '/lib/NotifyBotPlugin.php';
require_once __DIR__ . '/lib/NotifyRuleForm.php';
require_once __DIR__ . '/lib/NotifySend.php';
require_once __DIR__ . '/lib/NotifyPresets.php';
require_once __DIR__ . '/lib/NotifyAgentOps.php';

NotifyAgentOps::register();

ModuleManager::registerMenuGroup('notify.menu.group', 1120, '/notify/rules', '🔔');

Assets::register('notify', [
    'css' => ['assets/css/notify.css'],
    'js' => ['assets/js/notify.js'],
]);

$pages = [
    '/notify/rules' => ['permission' => 'notify.manage', 'capability' => 'SUPERADMIN_ACCESS', 'methods' => ['GET', 'POST'], 'file' => 'rules.php'],
    '/notify/rules/new' => ['permission' => 'notify.manage', 'capability' => 'SUPERADMIN_ACCESS', 'methods' => ['GET', 'POST'], 'file' => 'rule_edit.php'],
    '/notify/rules/{id}/edit' => ['permission' => 'notify.manage', 'capability' => 'SUPERADMIN_ACCESS', 'methods' => ['GET', 'POST'], 'file' => 'rule_edit.php'],
    '/notify/rules/{id}/test' => ['permission' => 'notify.manage', 'capability' => 'SUPERADMIN_ACCESS', 'methods' => ['GET', 'POST'], 'file' => 'rule_test.php'],
    '/notify/journal' => ['permission' => 'notify.journal', 'methods' => ['GET'], 'file' => 'journal.php'],
    '/notify/tasks' => ['permission' => 'notify.tasks.view', 'methods' => ['GET', 'POST'], 'file' => 'tasks.php'],
    '/notify/settings' => ['permission' => 'notify.manage', 'capability' => 'SUPERADMIN_ACCESS', 'methods' => ['GET', 'POST'], 'file' => 'settings.php'],
    '/notify/send' => ['permission' => 'notify.send', 'methods' => ['GET', 'POST'], 'file' => 'send.php'],
];

foreach ($pages as $path => $def) {
    $file = (string)$def['file'];
    $route = [
        'module' => 'notify',
        'methods' => $def['methods'],
        'permission' => $def['permission'],
        'handler' => static function () use ($file): void {
            require base_path('modules/notify/pages/' . $file);
        },
    ];
    if (!empty($def['capability'])) {
        $route['capability'] = $def['capability'];
    }
    ModuleManager::registerRoute($path, $route);
}

Cron::register('notify.tick', [
    'module' => 'notify',
    'label' => 'notify.cron.tick',
    'every' => 5,
    'handler' => static function (): array {
        $s = NotifyEngine::tick();
        return [
            'ok' => true,
            'processed' => (int)$s['fired'] + (int)$s['escalated'] + (int)$s['expired'],
            'message' => 'scanned=' . (int)$s['scanned'] . ' fired=' . (int)$s['fired']
                . ' escalated=' . (int)$s['escalated'] . ' expired=' . (int)$s['expired'],
        ] + $s;
    },
]);

// Producers (orders, procedures, vacations, …) emit notify.event; the engine journals + sends.
EventBus::on('notify.event', [NotifyEngine::class, 'onEvent']);
EventBus::on('bots.inbound', [NotifyInbound::class, 'onInbound']);

$registerNotifyBots = static function (): void {
    NotifyBotPlugin::register();
};
if (ModuleManager::isEnabled('bots')) {
    $registerNotifyBots();
}
EventBus::on('bots.ready', static function ($payload) use ($registerNotifyBots) {
    $registerNotifyBots();
    return is_array($payload) ? $payload : [];
});

// Shared enum source for catalog category conditions (orders + procedures).
EventBus::on('notify.options', static function ($payload) {
    if (is_array($payload) && ($payload['key'] ?? '') === 'catalog.categories' && class_exists('CategoriesRepository', false)) {
        foreach (CategoriesRepository::names() as $id => $name) {
            $payload['options'][(string)$id] = $name;
        }
    }
    return $payload;
});

// Client card: what was sent to / about this client.
EventBus::on('clients.view.profile', static function ($payload) {
    if (!is_array($payload) || !Auth::can('notify.journal') || !NotifyDeliveriesRepository::tableReady()) {
        return $payload;
    }
    $clientId = (int)($payload['id'] ?? ($payload['client']['id'] ?? 0));
    if ($clientId < 1) {
        return $payload;
    }
    Assets::need('notify');
    $payload['html'] = (string)($payload['html'] ?? '') . View::capture('_client_profile', [
        'client_id' => $clientId,
        'to_client' => NotifyDeliveriesRepository::list(['client_id' => $clientId, 'scope' => 'to_client', 'limit' => 20])['items'],
        'about_client' => NotifyDeliveriesRepository::list(['client_id' => $clientId, 'scope' => 'about_client', 'limit' => 20])['items'],
        'tasks' => NotifyTasksRepository::list(['client_id' => $clientId, 'status' => 'pending', 'limit' => 20])['items'],
    ], 'notify');
    return $payload;
});

EventBus::on('orders.view.sections', static function ($payload) {
    if (!is_array($payload) || !Auth::can('notify.tasks.view') || !NotifyTasksRepository::tableReady()) {
        return $payload;
    }
    $order = is_array($payload['order'] ?? null) ? $payload['order'] : [];
    $orderId = (int)($order['id'] ?? $payload['order_id'] ?? 0);
    if ($orderId < 1) {
        return $payload;
    }
    $tasks = NotifyTasksRepository::list([
        'entity' => 'order',
        'entity_id' => $orderId,
        'limit' => 20,
    ])['items'];
    $payload['html'] = (string)($payload['html'] ?? '') . View::capture('_order_section', [
        'order_id' => $orderId,
        'tasks' => $tasks,
        'can_resolve' => Auth::can('notify.tasks.resolve'),
    ], 'notify');
    return $payload;
});

EventBus::on('core.notices.collect', static function ($notices) {
    if (!is_array($notices)) {
        $notices = [];
    }
    if (!Auth::check() || !Auth::can('notify.tasks.view')) {
        return $notices;
    }
    $n = NotifyTasksRepository::countPending(true);
    if ($n > 0) {
        $notices[] = [
            'id' => 'notify.tasks',
            'tone' => 'warn',
            'href' => '/notify/tasks',
            'title' => t('notify.notice.tasks_title'),
            'short' => t('notify.notice.tasks_short', ['n' => (string)$n]),
        ];
    }
    return $notices;
});
