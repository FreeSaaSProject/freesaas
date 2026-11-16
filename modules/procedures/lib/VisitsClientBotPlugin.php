<?php
declare(strict_types=1);

/** Client bot: recent paid visits (procedures) for the linked client. */
final class VisitsClientBotPlugin
{
    public static function register(): void
    {
        if (!ModuleManager::isEnabled('bots') || !class_exists('BotPlugins', false)) {
            return;
        }
        if (!ModuleManager::isEnabled('procedures')) {
            return;
        }

        BotPlugins::register('visits', [
            'priority' => 25,
            'audiences' => ['client'],
            'label' => 'bots.catalog.client.visits',
            'module' => 'procedures',
            'cabinet_buttons' => static function (array $ctx): array {
                if (($ctx['audience'] ?? '') !== 'client') {
                    return [];
                }
                return [[[
                    'text' => t('bots.catalog.client.visits'),
                    'callback_data' => 'visits',
                ]]];
            },
            'handle_callback' => static function (array $ctx) {
                if (($ctx['audience'] ?? '') !== 'client') {
                    return null;
                }
                if ((string)($ctx['callback_data'] ?? '') !== 'visits') {
                    return null;
                }
                $user = is_array($ctx['user'] ?? null) ? $ctx['user'] : null;
                $cid = (int)($user['id'] ?? 0);
                if ($cid < 1) {
                    return true;
                }
                $items = [];
                if (class_exists('ProceduresRepository', false)) {
                    $list = ProceduresRepository::list(['id_user' => $cid, 'limit' => 15, 'offset' => 0]);
                    $items = is_array($list['items'] ?? null) ? $list['items'] : [];
                }
                if ($items === []) {
                    $text = t('procedures.bot.visits_empty');
                } else {
                    $lines = [t('procedures.bot.visits_title')];
                    foreach ($items as $row) {
                        $when = (int)($row['dtstart'] ?? $row['dt_start'] ?? $row['dt'] ?? 0);
                        $staff = (string)($row['operator_name'] ?? $row['admin_name'] ?? '');
                        $sum = (float)($row['summ'] ?? 0);
                        $lines[] = '•'
                            . ($when > 0 ? ' ' . date('d.m.Y H:i', $when) : '')
                            . ($staff !== '' ? ' — ' . $staff : '')
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
