<?php
declare(strict_types=1);

/** Client bot: list upcoming website orders for the linked client. */
final class OrdersClientBotPlugin
{
    public static function register(): void
    {
        if (!ModuleManager::isEnabled('bots') || !class_exists('BotPlugins', false)) {
            return;
        }
        if (!ModuleManager::isEnabled('orders')) {
            return;
        }

        BotPlugins::register('orders', [
            'priority' => 30,
            'audiences' => ['client'],
            'label' => 'bots.catalog.client.orders',
            'module' => 'orders',
            'cabinet_buttons' => static function (array $ctx): array {
                if (($ctx['audience'] ?? '') !== 'client') {
                    return [];
                }
                return [[[
                    'text' => t('bots.catalog.client.orders'),
                    'callback_data' => 'orders',
                ]]];
            },
            'handle_callback' => static function (array $ctx) {
                if (($ctx['audience'] ?? '') !== 'client') {
                    return null;
                }
                if ((string)($ctx['callback_data'] ?? '') !== 'orders') {
                    return null;
                }
                $user = is_array($ctx['user'] ?? null) ? $ctx['user'] : null;
                $cid = (int)($user['id'] ?? 0);
                if ($cid < 1) {
                    return true;
                }
                $rows = class_exists('OrdersRepository', false)
                    ? OrdersRepository::listFutureByClient($cid, 15)
                    : [];
                if ($rows === []) {
                    $text = t('orders.bot.empty');
                } else {
                    $lines = [t('orders.bot.title')];
                    foreach ($rows as $row) {
                        $when = (int)($row['dt_event'] ?? $row['dt_create'] ?? 0);
                        $sum = (float)($row['summ'] ?? 0);
                        $lines[] = '• #' . (int)($row['id'] ?? 0)
                            . ($when > 0 ? ' ' . date('d.m H:i', $when) : '')
                            . ($sum > 0 ? ' · ' . number_format($sum, 0, '.', ' ') : '');
                    }
                    $text = implode("\n", $lines);
                }
                BotClient::replyUi($ctx, $text, [
                    'inline_keyboard' => [[
                        ['text' => t('bots.cabinet.refresh'), 'callback_data' => 'cabinet'],
                    ]],
                ]);
                return true;
            },
        ]);
    }
}
