<?php
declare(strict_types=1);

/** Staff bot: choose dictaphone badge (oper employee_bot_badge). */
final class BadgeBotPlugin
{
    public static function register(): void
    {
        if (!ModuleManager::isEnabled('bots') || !class_exists('BotPlugins', false)) {
            return;
        }

        BotPlugins::register('badge', [
            'priority' => 23,
            'audiences' => ['staff'],
            'label' => 'bots.catalog.staff.badge',
            'module' => 'audio',
            'cabinet_buttons' => static function (array $ctx): array {
                if (($ctx['audience'] ?? '') !== 'staff') {
                    return [];
                }
                $user = is_array($ctx['user'] ?? null) ? $ctx['user'] : null;
                $uid = (int)($user['id'] ?? 0);
                if ($uid < 1) {
                    return [];
                }
                if (!BadgeAssignmentsRepository::analyticsEnabled($uid)) {
                    return [];
                }
                if (!BadgeAssignmentsRepository::onShiftToday($uid)) {
                    return [];
                }
                return [[[
                    'text' => t('bots.catalog.staff.badge'),
                    'callback_data' => 'badge_choose',
                ]]];
            },
            'handle_callback' => static function (array $ctx) {
                if (($ctx['audience'] ?? '') !== 'staff') {
                    return null;
                }
                $data = (string)($ctx['callback_data'] ?? '');
                if ($data === '' || strpos($data, 'badge') !== 0) {
                    return null;
                }
                $user = is_array($ctx['user'] ?? null) ? $ctx['user'] : null;
                $uid = (int)($user['id'] ?? 0);
                if ($uid < 1) {
                    return true;
                }
                return Auth::runAs($uid, static function () use ($ctx, $data, $uid) {
                    return self::onCallback($ctx, $data, $uid);
                });
            },
        ]);
    }

    /** @param array<string,mixed> $ctx */
    private static function onCallback(array $ctx, string $data, int $uid): bool
    {
        if ($data === 'badge_choose') {
            self::showChooser($ctx, $uid);
            return true;
        }
        if ($data === 'badge_not_on_shift') {
            $res = BadgeAssignmentsRepository::save($uid, 'not_on_shift');
            BotClient::replyUi($ctx, (string)$res['message'], self::kbMenu());
            return true;
        }
        if (preg_match('/^badge_(.+)$/', $data, $m)) {
            $code = (string)$m[1];
            if ($code === 'choose' || strpos($code, 'detach') === 0) {
                return false;
            }
            $res = BadgeAssignmentsRepository::save($uid, $code);
            BotClient::replyUi($ctx, (string)$res['message'], self::kbMenu());
            return true;
        }
        return false;
    }

    /** @param array<string,mixed> $ctx */
    private static function showChooser(array $ctx, int $uid): void
    {
        if (!BadgeAssignmentsRepository::analyticsEnabled($uid)) {
            BotClient::replyUi($ctx, t('audio.bot.badge.no_analytics'), self::kbMenu());
            return;
        }
        if (!BadgeAssignmentsRepository::onShiftToday($uid)) {
            BotClient::replyUi($ctx, t('audio.bot.badge.not_on_shift_day'), self::kbMenu());
            return;
        }

        $options = BadgeAssignmentsRepository::selectOptions(time(), $uid);
        $active = BadgeAssignmentsRepository::activeBadge($uid);
        $header = t('audio.bot.badge.title');
        if ($active !== null && $active !== '0') {
            $header .= "\n" . t('audio.bot.badge.current', ['code' => $active]);
        } elseif ($active === '0') {
            $header .= "\n" . t('audio.bot.badge.current_off');
        }

        $rows = [];
        $row = [];
        $i = 0;
        foreach ($options as $value => $label) {
            $cb = $value === 'not_on_shift' ? 'badge_not_on_shift' : ('badge_' . $value);
            // Telegram callback_data max 64 bytes.
            if (strlen($cb) > 64) {
                continue;
            }
            $row[] = ['text' => (string)$label, 'callback_data' => $cb];
            $i++;
            if ($i % 2 === 0) {
                $rows[] = $row;
                $row = [];
            }
        }
        if ($row !== []) {
            $rows[] = $row;
        }
        $rows[] = [[
            'text' => t('bots.cabinet.refresh'),
            'callback_data' => 'cabinet',
        ]];
        BotClient::replyUi($ctx, $header, ['inline_keyboard' => $rows]);
    }

    /** @return array{inline_keyboard:list<list<array<string,string>>>} */
    private static function kbMenu(): array
    {
        return ['inline_keyboard' => [
            [['text' => t('audio.bot.badge.again'), 'callback_data' => 'badge_choose']],
            [['text' => t('bots.cabinet.refresh'), 'callback_data' => 'cabinet']],
        ]];
    }
}
