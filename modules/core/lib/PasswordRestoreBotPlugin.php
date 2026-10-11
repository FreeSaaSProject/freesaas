<?php
declare(strict_types=1);

/** Staff bot: generate a new FreeSaaS login password (enabled users only). */
final class PasswordRestoreBotPlugin
{
    private const EXPIRE_DAYS = 90;

    public static function register(): void
    {
        if (!ModuleManager::isEnabled('bots') || !class_exists('BotPlugins', false)) {
            return;
        }

        BotPlugins::register('password_restore', [
            'priority' => 40,
            'audiences' => ['staff'],
            'label' => 'bots.catalog.staff.password_restore',
            'module' => 'core',
            'cabinet_buttons' => static function (array $ctx): array {
                if (($ctx['audience'] ?? '') !== 'staff') {
                    return [];
                }
                return [[[
                    'text' => t('bots.catalog.staff.password_restore'),
                    'callback_data' => 'password_restore',
                ]]];
            },
            'handle_callback' => static function (array $ctx) {
                if (($ctx['audience'] ?? '') !== 'staff') {
                    return null;
                }
                $data = (string)($ctx['callback_data'] ?? '');
                if ($data !== 'password_restore' && $data !== 'password_restore_do') {
                    return null;
                }
                $user = is_array($ctx['user'] ?? null) ? $ctx['user'] : null;
                $uid = (int)($user['id'] ?? 0);
                if ($uid < 1) {
                    return true;
                }
                if ($data === 'password_restore') {
                    self::showPrompt($ctx, $uid);
                    return true;
                }
                self::doRestore($ctx, $uid);
                return true;
            },
        ]);
    }

    /** @param array<string,mixed> $ctx */
    private static function showPrompt(array $ctx, int $uid): void
    {
        $enabled = self::isEnabled($uid);
        $kb = ['inline_keyboard' => []];
        if ($enabled) {
            $text = t('core.bot.password_restore.prompt');
            $kb['inline_keyboard'][] = [[
                'text' => t('core.bot.password_restore.generate'),
                'callback_data' => 'password_restore_do',
            ]];
        } else {
            $text = t('core.bot.password_restore.disabled');
        }
        $kb['inline_keyboard'][] = [[
            'text' => t('bots.cabinet.refresh'),
            'callback_data' => 'cabinet',
        ]];
        self::reply($ctx, $text, $kb);
    }

    /** @param array<string,mixed> $ctx */
    private static function doRestore(array $ctx, int $uid): void
    {
        $menuKb = ['inline_keyboard' => [[[
            'text' => t('bots.cabinet.refresh'),
            'callback_data' => 'cabinet',
        ]]]];
        if (!self::isEnabled($uid)) {
            self::reply($ctx, t('core.bot.password_restore.disabled'), $menuKb);
            return;
        }
        $plain = self::generatePassword(12);
        try {
            UsersRepository::update($uid, [
                'password_plain' => $plain,
                'expire' => time() + self::EXPIRE_DAYS * 86400,
                'retries' => 0,
            ]);
        } catch (Throwable $e) {
            self::reply($ctx, t('core.bot.password_restore.update_error'), $menuKb);
            return;
        }
        $safe = htmlspecialchars($plain, ENT_QUOTES, 'UTF-8');
        $text = t('core.bot.password_restore.done', [
            'password' => $safe,
            'days' => (string)self::EXPIRE_DAYS,
        ]);
        self::replyHtml($ctx, $text, $menuKb);
    }

    private static function isEnabled(int $uid): bool
    {
        $row = UsersRepository::find($uid);
        if ($row === null) {
            return false;
        }
        return (string)($row['enabled'] ?? '') === '1';
    }

    /** ≥12 chars from 62-symbol alphabet (~71 bits at len=12). */
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
