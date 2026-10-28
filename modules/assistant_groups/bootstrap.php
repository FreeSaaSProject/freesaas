<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/AssistantGroupsConfig.php';
require_once __DIR__ . '/lib/AssistantGroupsCatalog.php';
require_once __DIR__ . '/lib/AssistantGroupsChats.php';
require_once __DIR__ . '/lib/AssistantGroupsDialog.php';
require_once __DIR__ . '/lib/MaxGroupSpike.php';
require_once __DIR__ . '/lib/AssistantGroupsInbound.php';
require_once __DIR__ . '/lib/AssistantGroupsPoller.php';
require_once __DIR__ . '/lib/AssistantGroupsWebhook.php';
require_once __DIR__ . '/lib/AssistantGroupsProblemStore.php';
require_once __DIR__ . '/lib/AssistantGroupsKeyboards.php';
require_once __DIR__ . '/lib/AssistantGroupsChoices.php';
require_once __DIR__ . '/lib/AssistantGroupsBotHandler.php';

Assets::register('assistant_groups', [
    'css' => ['assistant_groups.css'],
    'js' => ['assistant_groups.js'],
]);

ModuleManager::registerRoute('/assistant-groups/settings', [
    'module' => 'assistant_groups',
    'methods' => ['GET', 'POST'],
    'capability' => 'SUPERADMIN_ACCESS',
    'handler' => static function (): void {
        require base_path('modules/assistant_groups/pages/settings.php');
    },
]);

ModuleManager::registerRoute('/assistant-groups/status', [
    'module' => 'assistant_groups',
    'methods' => ['GET'],
    'capability' => 'SUPERADMIN_ACCESS',
    'handler' => static function (): void {
        require base_path('modules/assistant_groups/pages/status.php');
    },
]);

ModuleManager::registerRoute('/assistant-groups/webhook/max', [
    'module' => 'assistant_groups',
    'methods' => ['POST'],
    'public' => true,
    'csrf' => false, // Max POST; auth via X-Max-Bot-Api-Secret
    'handler' => static function (): void {
        AssistantGroupsWebhook::handleHttp();
    },
]);

AssistantGroupsBotHandler::register();

Cron::register('assistant_groups.max_poll', [
    'module' => 'assistant_groups',
    'label' => 'assistant_groups.cron.max_poll',
    'every' => 1,
    'handler' => static function (): array {
        // Fallback when no Max webhook subscription (dev / before subscribe).
        // Skips automatically if webhook_subscribed or Max reports subscriptions.
        $total = 0;
        $err = '';
        for ($i = 0; $i < 3; $i++) {
            $r = AssistantGroupsPoller::tick(8);
            $total += (int)($r['processed'] ?? 0);
            $e = (string)($r['error'] ?? '');
            if (empty($r['ok']) && $e !== '' && !in_array($e, ['disabled', 'no_own_token', 'webhook_active'], true)) {
                $err = $e;
            }
            if ($e === 'webhook_active') {
                return ['ok' => true, 'processed' => 0, 'error' => 'webhook_active', 'skipped' => 1];
            }
            if ((int)($r['processed'] ?? 0) === 0 && $i > 0) {
                break;
            }
        }
        return [
            'ok' => $err === '',
            'processed' => $total,
            'error' => $err,
        ];
    },
]);
