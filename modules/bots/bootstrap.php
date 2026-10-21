<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/BotsConfig.php';
require_once __DIR__ . '/lib/BotClient.php';
require_once __DIR__ . '/lib/BotEphemeral.php';
require_once __DIR__ . '/lib/BotLinksRepository.php';
require_once __DIR__ . '/lib/BotIdentity.php';
require_once __DIR__ . '/lib/BotSendsRepository.php';
require_once __DIR__ . '/lib/BotDrain.php';
require_once __DIR__ . '/lib/BotPlugins.php';
require_once __DIR__ . '/lib/BotMenuCatalog.php';
require_once __DIR__ . '/lib/BotSend.php';
require_once __DIR__ . '/lib/BotRecipients.php';
require_once __DIR__ . '/lib/BotsAssistantPreview.php';
require_once __DIR__ . '/lib/BotWebhook.php';
require_once __DIR__ . '/lib/BotWebhookUpdatesRepository.php';
require_once __DIR__ . '/lib/BotLinkCodesRepository.php';
require_once __DIR__ . '/lib/BotChatState.php';
require_once __DIR__ . '/lib/BotsAgentOps.php';

BotsAgentOps::register();

ModuleManager::registerMenuGroup('bots.menu.group', 1100, '/bots/types/staff');

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
    // Staff messaging for personal assistant (no SUPERADMIN gate).
    '/bots/recipients' => [
        'permission' => 'bots.message',
        'methods' => ['GET'],
        'file' => 'recipients.php',
    ],
    '/bots/message' => [
        'permission' => 'bots.message',
        'methods' => ['POST'],
        'file' => 'message.php',
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

EventBus::on('assistant.write.preview', static function ($payload) {
    if (!is_array($payload)) {
        return [];
    }
    return BotsAssistantPreview::onWritePreview($payload);
});

EventBus::on('assistant.system.hints', static function ($payload) {
    if (!is_array($payload)) {
        return [];
    }
    $opIds = is_array($payload['op_ids'] ?? null) ? $payload['op_ids'] : [];
    $want = false;
    foreach ($opIds as $id) {
        $id = (string)$id;
        if ($id === 'bots.message.send' || $id === 'bots.recipients.list' || strpos($id, 'bots.message.') === 0) {
            $want = true;
            break;
        }
    }
    if (!$want) {
        return $payload;
    }
    $hints = is_array($payload['hints'] ?? null) ? $payload['hints'] : [];
    $hints[] = t('bots.assistant.hint');
    $payload['hints'] = $hints;
    return $payload;
});

Cron::register('bots.sends_drain', [
    'module' => 'bots',
    'label' => 'bots.cron.sends_drain',
    'every' => 5,
    'handler' => static function (): array {
        $r = BotDrain::run(40);
        $errors = (int)($r['errors'] ?? 0);
        return [
            'ok' => $errors === 0,
            'processed' => (int)($r['processed'] ?? 0),
            'sent' => (int)($r['sent'] ?? 0),
            'skipped' => (int)($r['skipped'] ?? 0),
            'errors' => $errors,
            'message' => 'sent=' . (int)($r['sent'] ?? 0) . ' errors=' . $errors,
        ];
    },
]);

ModuleManager::registerRoute('/bots/webhook/telegram/{channel}', [
    'module' => 'bots',
    'methods' => ['POST'],
    'public' => true,
    'handler' => static function (): void {
        BotWebhook::handle('telegram', (string)(Router::param('channel') ?? ''));
    },
]);

ModuleManager::registerRoute('/bots/webhook/max/{channel}', [
    'module' => 'bots',
    'methods' => ['POST'],
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

$botSaveFromPost = static function (int $source, int $entityId, array $post, bool $withNoSend): void {
    if ($entityId < 1 || !BotLinksRepository::tableReady()) {
        return;
    }
    $data = [
        'telegram_chat_id' => trim((string)($post['telegram_chat_id'] ?? '')),
        'max_user_id' => trim((string)($post['max_user_id'] ?? '')),
    ];
    if ($withNoSend) {
        $data['BOT_NO_SEND'] = !empty($post['BOT_NO_SEND']) ? 1 : 0;
    } else {
        $existing = BotLinksRepository::find($source, $entityId);
        $data['BOT_NO_SEND'] = $existing['BOT_NO_SEND'] ?? 0;
    }
    BotLinksRepository::upsert($source, $entityId, $data);
};

$botEnrichList = static function ($payload, int $source) {
    if (!is_array($payload)) {
        return $payload;
    }
    $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
    if ($items === [] || !BotLinksRepository::tableReady()) {
        return $payload;
    }
    $ids = [];
    foreach ($items as $row) {
        if (is_array($row) && (int)($row['id'] ?? 0) > 0) {
            $ids[] = (int)$row['id'];
        }
    }
    $map = BotLinksRepository::mapForEntities($source, $ids);
    foreach ($items as $i => $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = (int)($row['id'] ?? 0);
        $link = $map[$id] ?? null;
        $items[$i]['telegram_chat_id'] = $link['telegram_chat_id'] ?? '';
        $items[$i]['max_user_id'] = $link['max_user_id'] ?? '';
    }
    $payload['items'] = $items;
    $cols = is_array($payload['extra_columns'] ?? null) ? $payload['extra_columns'] : [];
    $cols[] = ['key' => 'telegram_chat_id', 'label' => t('bots.user_edit.telegram_chat_id')];
    $cols[] = ['key' => 'max_user_id', 'label' => t('bots.user_edit.max_user_id')];
    $payload['extra_columns'] = $cols;
    return $payload;
};

EventBus::on('core.user_edit.sections', static function ($payload) {
    if (!is_array($payload)) {
        return $payload;
    }
    $user = $payload['user'] ?? null;
    if (!is_array($user)) {
        return $payload;
    }
    $entity = BotLinksRepository::mergeOnto($user, BotLinksRepository::SOURCE_STAFF);
    $payload['user'] = $entity;
    $secTitle = t('bots.user_edit.sec');
    $secHint = t('bots.user_edit.hint');
    $showNoSend = true;
    ob_start();
    require __DIR__ . '/views/_entity_edit_bot.php';
    $payload['html'] = ($payload['html'] ?? '') . (string)ob_get_clean();
    return $payload;
});

EventBus::on('core.user_edit.save', static function ($payload) use ($botSaveFromPost) {
    if (!is_array($payload)) {
        return $payload;
    }
    $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
    $post = is_array($payload['post'] ?? null) ? $payload['post'] : [];
    $id = (int)($payload['id'] ?? 0);
    // Never write messenger ids onto base_users.
    unset($data['telegram_chat_id'], $data['max_user_id'], $data['BOT_NO_SEND']);
    $payload['data'] = $data;
    $botSaveFromPost(BotLinksRepository::SOURCE_STAFF, $id, $post, true);
    return $payload;
});

EventBus::on('core.user_edit.profile', static function ($payload) {
    if (!is_array($payload)) {
        return $payload;
    }
    // Telegram / Max / bot_no_send — director-only on staff profile card.
    if (empty($payload['can_see_sensitive'])) {
        return $payload;
    }
    $user = $payload['user'] ?? null;
    if (!is_array($user)) {
        return $payload;
    }
    $entity = BotLinksRepository::mergeOnto($user, BotLinksRepository::SOURCE_STAFF);
    $payload['user'] = $entity;
    $showNoSend = true;
    ob_start();
    require __DIR__ . '/views/_entity_profile_bot.php';
    $payload['html_channels'] = ($payload['html_channels'] ?? '') . (string)ob_get_clean();
    return $payload;
});

EventBus::on('core.users.list', static function ($payload) use ($botEnrichList) {
    return $botEnrichList($payload, BotLinksRepository::SOURCE_STAFF);
});

EventBus::on('clients.edit.sections', static function ($payload) {
    if (!is_array($payload)) {
        return $payload;
    }
    $client = $payload['client'] ?? null;
    if (!is_array($client)) {
        return $payload;
    }
    $entity = BotLinksRepository::mergeOnto($client, BotLinksRepository::SOURCE_CLIENT);
    $payload['client'] = $entity;
    $secTitle = t('bots.client_edit.sec');
    $secHint = t('bots.client_edit.hint');
    $showNoSend = false;
    ob_start();
    require __DIR__ . '/views/_entity_edit_bot.php';
    $payload['html'] = ($payload['html'] ?? '') . (string)ob_get_clean();
    return $payload;
});

EventBus::on('clients.edit.save', static function ($payload) use ($botSaveFromPost) {
    if (!is_array($payload)) {
        return $payload;
    }
    $post = is_array($payload['post'] ?? null) ? $payload['post'] : [];
    $id = (int)($payload['id'] ?? 0);
    $botSaveFromPost(BotLinksRepository::SOURCE_CLIENT, $id, $post, false);
    return $payload;
});

EventBus::on('clients.view.profile', static function ($payload) {
    if (!is_array($payload)) {
        return $payload;
    }
    $client = $payload['client'] ?? null;
    if (!is_array($client)) {
        return $payload;
    }
    $entity = BotLinksRepository::mergeOnto($client, BotLinksRepository::SOURCE_CLIENT);
    $payload['client'] = $entity;
    $showNoSend = false;
    ob_start();
    require __DIR__ . '/views/_entity_profile_bot.php';
    $payload['html'] = ($payload['html'] ?? '') . (string)ob_get_clean();
    return $payload;
});

EventBus::on('clients.list', static function ($payload) use ($botEnrichList) {
    return $botEnrichList($payload, BotLinksRepository::SOURCE_CLIENT);
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
        'status' => 'skipped',
        'error_code' => 'bots_token_unconfigured',
        'message' => t('bots.health.missing'),
        'meta' => ['hint' => '/bots/settings'],
    ];
}, 'Bot tokens');

Health::register('bots.webhook_secrets', 'bots', static function (): array {
    $missing = BotsConfig::channelsMissingSecret();
    if ($missing === []) {
        if (!BotsConfig::anyTokenConfigured()) {
            return [
                'status' => 'skipped',
                'message' => t('bots.health.secrets_skipped'),
            ];
        }
        return [
            'status' => 'ok',
            'message' => t('bots.health.secrets_ok'),
        ];
    }
    return [
        'status' => 'degraded',
        'error_code' => 'bots_webhook_secret_missing',
        'message' => t('bots.health.secrets_missing', ['channels' => implode(', ', $missing)]),
        'meta' => [
            'channels' => $missing,
            'hint' => '/bots/settings',
            'allow_unsigned' => BotsConfig::allowUnsignedWebhooks(),
        ],
    ];
}, 'Bot webhook secrets');

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
