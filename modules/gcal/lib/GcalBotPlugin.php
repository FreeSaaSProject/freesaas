<?php
declare(strict_types=1);

final class GcalBotPlugin
{
    public static function register(): void
    {
        if (!class_exists('BotPlugins')) {
            return;
        }
        BotPlugins::register('gcal', [
            'priority' => 85,
            'audiences' => ['staff'],
            'label' => 'gcal.module.name',
            'module' => 'gcal',
            'cabinet_buttons' => static function (array $ctx): array {
                if (!GcalConfig::enabled() || !GcalConfig::appConfigured()) {
                    return [];
                }
                $uid = (int)($ctx['user_id'] ?? 0);
                if ($uid <= 0) {
                    return [];
                }
                $tok = GcalSyncRepository::getToken($uid);
                $on = $tok && trim((string)$tok['REFRESH_TOKEN']) !== '';
                $label = $on ? t('gcal.bot.status_on') : t('gcal.bot.status_off');
                $rows = [[
                    ['text' => $label, 'callback_data' => 'gcal:status'],
                ]];
                if (!$on) {
                    $rows[] = [
                        ['text' => t('gcal.bot.connect'), 'callback_data' => 'gcal:connect'],
                    ];
                }
                return $rows;
            },
            'handle_callback' => static function (array $ctx) {
                $data = (string)($ctx['callback_data'] ?? '');
                if (strpos($data, 'gcal:') !== 0) {
                    return null;
                }
                $uid = (int)($ctx['user_id'] ?? 0);
                $reply = $ctx['reply'] ?? null;
                if ($data === 'gcal:status') {
                    $tok = GcalSyncRepository::getToken($uid);
                    $on = $tok && trim((string)$tok['REFRESH_TOKEN']) !== '';
                    $msg = $on ? t('gcal.bot.status_on') : t('gcal.bot.status_off');
                    if (is_callable($reply)) {
                        $reply($msg);
                    }
                    return true;
                }
                if ($data === 'gcal:connect') {
                    if (!GcalConfig::appConfigured()) {
                        if (is_callable($reply)) {
                            $reply(t('gcal.err.app'));
                        }
                        return true;
                    }
                    $url = GcalOAuth::authUrl($uid);
                    if (is_callable($reply)) {
                        $reply(t('gcal.bot.connect') . "\n" . $url);
                    }
                    return true;
                }
                return null;
            },
        ]);
    }
}
