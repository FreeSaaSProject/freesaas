<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$message = '';
$error = '';

$build = static function (): array {
    return [
        'group_enabled' => AssistantGroupsConfig::isEnabled() ? 1 : 0,
        'group_require_mention' => AssistantGroupsConfig::requireMention() ? 1 : 0,
        'auto_add_groups' => AssistantGroupsConfig::autoAddDiscovered() ? 1 : 0,
        'group_chat_ids' => implode(',', AssistantGroupsConfig::groupChatIds()),
        'group_chats' => AssistantGroupsConfig::groupChatsDetailed(),
        'group_ops' => implode(',', AssistantGroupsConfig::groupOpsPatterns()),
        'group_ops_extra' => implode(',', AssistantGroupsCatalog::orphanPatterns()),
        'ops_catalog' => AssistantGroupsCatalog::settingsCatalog(),
        'discovered_chats' => AssistantGroupsChats::listDiscovered(),
        'has_token' => AssistantGroupsConfig::hasOwnToken() ? 1 : 0,
        'token_preview' => AssistantGroupsConfig::maskedTokenPreview(),
        'token_source' => AssistantGroupsConfig::hasOwnToken() ? 'assistant_groups' : 'bots.staff_max',
        'webhook_url' => AssistantGroupsWebhook::defaultWebhookUrl(),
        'webhook_secret_set' => AssistantGroupsConfig::webhookSecret() !== '' ? 1 : 0,
        'webhook_subscribed' => AssistantGroupsConfig::webhookSubscribedFlag() ? 1 : 0,
    ];
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $action = trim((string)($_POST['action'] ?? 'save'));
        if ($action === 'subscribe') {
            AssistantGroupsConfig::saveFromPost($_POST);
            $res = AssistantGroupsWebhook::subscribe(
                trim((string)($_POST['webhook_url'] ?? '')) !== ''
                    ? (string)$_POST['webhook_url']
                    : null
            );
            if (!$res['ok']) {
                throw new RuntimeException($res['error'] !== '' ? $res['error'] : 'subscribe failed');
            }
            $message = t('assistant_groups.settings.subscribed', ['url' => $res['url']]);
        } elseif ($action === 'unsubscribe') {
            $res = AssistantGroupsWebhook::unsubscribeAll();
            if (!$res['ok']) {
                throw new RuntimeException($res['error'] !== '' ? $res['error'] : 'unsubscribe failed');
            }
            $message = t('assistant_groups.settings.unsubscribed');
        } elseif ($action === 'discover_add') {
            $ids = isset($_POST['discovered_id']) && is_array($_POST['discovered_id'])
                ? array_map('strval', $_POST['discovered_id'])
                : [];
            $n = AssistantGroupsChats::addToAllowlist($ids);
            $message = t('assistant_groups.settings.discovered_added', ['n' => (string)$n]);
        } elseif ($action === 'discover_dismiss') {
            $ids = isset($_POST['discovered_id']) && is_array($_POST['discovered_id'])
                ? array_map('strval', $_POST['discovered_id'])
                : [];
            AssistantGroupsChats::dismiss($ids);
            $message = t('assistant_groups.settings.discovered_dismissed');
        } else {
            AssistantGroupsConfig::saveFromPost($_POST);
            $message = t('core.common.saved');
        }
        if ($wantsJson) {
            AgentHttp::emit(['ok' => true, 'saved' => true, 'action' => $action, 'settings' => $build()]);
            return;
        }
        flash_set('ok', $message);
        redirect('/assistant-groups/settings');
    } catch (Throwable $e) {
        AgentHttp::rethrowIfCapture($e);
        if ($wantsJson) {
            AgentHttp::error($e->getMessage(), 400);
            return;
        }
        $error = $e->getMessage();
    }
}

$settings = $build();
if ($wantsJson) {
    AgentHttp::emit(['ok' => true, 'settings' => $settings]);
    return;
}

View::render('settings', [
    'title' => t('assistant_groups.settings.title'),
    'message' => $message,
    'error' => $error,
    'settings' => $settings,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('assistant_groups.menu.settings'), 'current' => true],
    ]),
], 'layout', 'assistant_groups');
