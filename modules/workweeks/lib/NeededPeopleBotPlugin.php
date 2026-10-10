<?php
declare(strict_types=1);

/** Staff bot: days with open masseur slots (oper needed_people). */
final class NeededPeopleBotPlugin
{
    public static function register(): void
    {
        if (!ModuleManager::isEnabled('bots') || !class_exists('BotPlugins', false)) {
            return;
        }

        BotPlugins::register('needed_people', [
            'priority' => 22,
            'audiences' => ['staff'],
            'label' => 'bots.catalog.staff.needed_people',
            'module' => 'workweeks',
            'cabinet_buttons' => static function (array $ctx): array {
                if (($ctx['audience'] ?? '') !== 'staff') {
                    return [];
                }
                $dates = WorkweeksRepository::neededPeopleFutureDates(null, 90);
                if ($dates === []) {
                    return [];
                }
                return [[[
                    'text' => t('bots.catalog.staff.needed_people'),
                    'callback_data' => 'needed_people',
                ]]];
            },
            'handle_callback' => static function (array $ctx) {
                if (($ctx['audience'] ?? '') !== 'staff') {
                    return null;
                }
                if ((string)($ctx['callback_data'] ?? '') !== 'needed_people') {
                    return null;
                }
                $user = is_array($ctx['user'] ?? null) ? $ctx['user'] : null;
                $uid = (int)($user['id'] ?? 0);
                if ($uid < 1) {
                    return true;
                }
                return Auth::runAs($uid, static function () use ($ctx) {
                    $dates = WorkweeksRepository::neededPeopleFutureDates(null, 90);
                    if ($dates === []) {
                        $text = t('workweeks.bot.needed_people.empty');
                    } else {
                        $lines = [t('workweeks.bot.needed_people.title')];
                        foreach ($dates as $d) {
                            $day = t('workweeks.bot.day.' . (string)$d['day_key']);
                            $lines[] = t('workweeks.bot.needed_people.line', [
                                'date' => (string)$d['date_formatted'],
                                'day' => $day,
                            ]);
                        }
                        $text = implode("\n", $lines);
                    }
                    BotClient::replyUi($ctx, $text, [
                        'inline_keyboard' => [[[
                            'text' => t('bots.cabinet.refresh'),
                            'callback_data' => 'cabinet',
                        ]]],
                    ]);
                    return true;
                });
            },
        ]);
    }
}
