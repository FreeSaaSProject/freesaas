<?php
declare(strict_types=1);

/**
 * Client bot: generate a new cabinet password (linked enabled clients).
 * Mirrors staff PasswordRestoreBotPlugin, but writes base_clients via ClientPortalAuth.
 */
final class ClientPasswordRestoreBotPlugin
{
    public static function register(): void
    {
        if (!ModuleManager::isEnabled('bots') || !class_exists('BotPlugins', false)) {
            return;
        }
        if (!ModuleManager::isEnabled('client_portal') && !ModuleManager::isConfigEnabled('client_portal')) {
            return;
        }

        BotPlugins::register('client_password_restore', [
            'priority' => 45,
            'audiences' => ['client'],
            'label' => 'bots.catalog.client.password_restore',
            'module' => 'client_portal',
            'cabinet_buttons' => static function (array $ctx): array {
                if (($ctx['audience'] ?? '') !== 'client') {
                    return [];
                }
                return [[[
                    'text' => t('bots.catalog.client.password_restore'),
                    'callback_data' => 'client_password_restore',
                ]]];
            },
            'handle_callback' => static function (array $ctx) {
                if (($ctx['audience'] ?? '') !== 'client') {
                    return null;
                }
                $data = (string)($ctx['callback_data'] ?? '');
                if ($data !== 'client_password_restore' && $data !== 'client_password_restore_do') {
                    return null;
                }
                $user = is_array($ctx['user'] ?? null) ? $ctx['user'] : null;
                $cid = (int)($user['id'] ?? 0);
                if ($cid < 1) {
                    return true;
                }
                // One tap: generate immediately (no web form / token).
                self::doRestore($ctx, $cid);
                return true;
            },
        ]);
    }

    /** @param array<string,mixed> $ctx */
    private static function doRestore(array $ctx, int $clientId): void
    {
        $menuKb = ['inline_keyboard' => [[[
            'text' => t('bots.cabinet.refresh'),
            'callback_data' => 'cabinet',
        ]]]];
        if (!self::isEnabled($clientId)) {
            self::reply($ctx, t('client_portal.bot.password_restore.disabled'), $menuKb);
            return;
        }
        $plain = self::generatePassword(12);
        try {
            ClientPortalAuth::storePassword($clientId, $plain);
        } catch (Throwable $e) {
            self::reply($ctx, t('client_portal.bot.password_restore.update_error'), $menuKb);
            return;
        }
        $platform = strtolower((string)($ctx['platform'] ?? 'telegram'));
        if ($platform === 'telegram') {
            $safe = htmlspecialchars($plain, ENT_QUOTES, 'UTF-8');
            $text = t('client_portal.bot.password_restore.done_html', ['password' => $safe]);
            self::replyHtml($ctx, $text, $menuKb);
            return;
        }
        // Max / others: plain text (no tg-spoiler).
        $text = t('client_portal.bot.password_restore.done', ['password' => $plain]);
        self::reply($ctx, $text, $menuKb);
    }

    private static function isEnabled(int $clientId): bool
    {
        if (!class_exists('ClientsRepository', false)) {
            return false;
        }
        $row = ClientsRepository::find($clientId);
        if ($row === null) {
            return false;
        }
        return (int)($row['enabled'] ?? 0) === 1;
    }

    private static function generatePassword(int $len = 12): string
    {
        $len = max(12, $len);
        $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $max = strlen($alphabet) - 1;
        $out = '';
        for ($i = 0; $i < $len; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $ctx
     * @param array<string,mixed>|null $markup
     */
    private static function reply(array $ctx, string $text, ?array $markup = null): void
    {
        if ((string)($ctx['token'] ?? '') === '' || (string)($ctx['chat_id'] ?? '') === '') {
            return;
        }
        BotClient::replyUi($ctx, $text, $markup);
    }

    /**
     * @param array<string,mixed> $ctx
     * @param array<string,mixed>|null $markup
     */
    private static function replyHtml(array $ctx, string $text, ?array $markup = null): void
    {
        if ((string)($ctx['token'] ?? '') === '' || (string)($ctx['chat_id'] ?? '') === '') {
            return;
        }
        BotClient::replyUi($ctx, $text, $markup, 'HTML');
    }
}
