<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/BotsConfig.php';
require_once __DIR__ . '/lib/BotClient.php';
require_once __DIR__ . '/lib/BotIdentity.php';
require_once __DIR__ . '/lib/BotSendsRepository.php';
require_once __DIR__ . '/lib/BotDrain.php';
require_once __DIR__ . '/lib/BotPlugins.php';
require_once __DIR__ . '/lib/BotMenuCatalog.php';
require_once __DIR__ . '/lib/BotSend.php';
require_once __DIR__ . '/lib/BotWebhook.php';

ModuleManager::registerMenuGroup('bots.menu.group', 68, '/bots/types/staff');

$pages = [
    '/bots/types/{audience}' => [
        'permission' => 'bots.view',
        'capability' => 'SUPERADMIN_ACCESS',
        'methods' => ['GET'],
        'file' => 'type.php',
    ],
    '/bots/settings' => [
        'permission' => 'bots.manage',
        'capability' => 'SUPERADMIN_ACCESS',
        'methods' => ['GET', 'POST'],
        'file' => 'settings.php',
    ],
    '/bots/sends' => [
        'permission' => 'bots.view',
        'capability' => 'SUPERADMIN_ACCESS',
        'methods' => ['GET'],
        'file' => 'sends.php',
    ],
    '/bots/sends/work' => [
        'permission' => 'bots.manage',
        'capability' => 'SUPERADMIN_ACCESS',
        'methods' => ['GET', 'POST'],
        'file' => 'sends_work.php',
    ],
    '/bots/sends/test' => [
        'permission' => 'bots.manage',
        'capability' => 'SUPERADMIN_ACCESS',
        'methods' => ['POST'],
        'file' => 'sends_test.php',
    ],
    '/bots/plugins' => [
        'permission' => 'bots.manage',
        'capability' => 'SUPERADMIN_ACCESS',
        'methods' => ['GET'],
        'file' => 'plugins.php',
    ],
];

foreach ($pages as $path => $def) {
    $file = (string)$def['file'];
    $route = [
        'module' => 'bots',
        'methods' => $def['methods'],
        'permission' => $def['permission'],
        'handler' => static function () use ($file): void {
            require base_path('modules/bots/pages/' . $file);
        },
    ];
    if (!empty($def['capability'])) {
        $route['capability'] = $def['capability'];
    }
    ModuleManager::registerRoute($path, $route);
}

ModuleManager::registerRoute('/bots/webhook/telegram/{channel}', [
    'module' => 'bots',
    'methods' => ['POST', 'GET'],
    'public' => true,
    'handler' => static function (): void {
        BotWebhook::handle('telegram', (string)(Router::param('channel') ?? ''));
    },
]);

ModuleManager::registerRoute('/bots/webhook/max/{channel}', [
    'module' => 'bots',
    'methods' => ['POST', 'GET'],
    'public' => true,
    'handler' => static function (): void {
        BotWebhook::handle('max', (string)(Router::param('channel') ?? ''));
    },
]);

$onSend = static function ($payload) {
    return BotSend::onSend($payload);
};
EventBus::on('bots.send', $onSend);
EventBus::on('notify.send', $onSend);

EventBus::on('core.user_edit.sections', static function ($payload) {
    if (!is_array($payload)) {
        return $payload;
    }
    $user = $payload['user'] ?? null;
    if (!is_array($user)) {
        return $payload;
    }
    ob_start();
    require __DIR__ . '/views/_user_edit_bot.php';
    $payload['html'] = ($payload['html'] ?? '') . (string)ob_get_clean();
    return $payload;
});

EventBus::on('core.user_edit.save', static function ($payload) {
    if (!is_array($payload)) {
        return $payload;
    }
    $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
    $post = is_array($payload['post'] ?? null) ? $payload['post'] : [];
    $data['telegram_chat_id'] = trim((string)($post['telegram_chat_id'] ?? ''));
    $data['max_user_id'] = trim((string)($post['max_user_id'] ?? ''));
    $data['BOT_NO_SEND'] = !empty($post['BOT_NO_SEND']) ? 1 : 0;
    $payload['data'] = $data;
    return $payload;
});

EventBus::on('core.user_edit.profile', static function ($payload) {
    if (!is_array($payload)) {
        return $payload;
    }
    $user = $payload['user'] ?? null;
    if (!is_array($user)) {
        return $payload;
    }
    ob_start();
    require __DIR__ . '/views/_user_profile_bot.php';
    $payload['html_channels'] = ($payload['html_channels'] ?? '') . (string)ob_get_clean();
    return $payload;
});

Health::register('bots.tokens', 'bots', static function (): array {
    if (BotsConfig::anyTokenConfigured()) {
        return [
            'status' => 'ok',
            'message' => t('bots.health.ok'),
            'meta' => ['channels' => BotsConfig::listForUi()],
        ];
    }
    return [
        'status' => 'degraded',
        'message' => t('bots.health.missing'),
        'meta' => ['hint' => '/bots/settings'],
    ];
}, 'Bot tokens');

Health::register('bots.queue', 'bots', static function (): array {
    if (!BotSendsRepository::tableReady()) {
        return [
            'status' => 'fail',
            'message' => t('bots.health.queue_missing'),
        ];
    }
    $n = BotSendsRepository::countPending();
    return [
        'status' => 'ok',
        'message' => t('bots.health.queue', ['n' => $n]),
        'meta' => ['pending' => $n],
    ];
}, 'Bot outbound queue');

// Modules that boot before `bots` (e.g. assistant) wait on this to register BotPlugins.
EventBus::emit('bots.ready', ['module' => 'bots']);
