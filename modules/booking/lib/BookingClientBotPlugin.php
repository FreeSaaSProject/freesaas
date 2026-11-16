<?php
declare(strict_types=1);

/**
 * Client bot: open online booking on the storefront (deep link).
 * Full slot FSM in-bot is not ported yet — button restores the oper «Запись» entry point.
 */
final class BookingClientBotPlugin
{
    public static function register(): void
    {
        if (!ModuleManager::isEnabled('bots') || !class_exists('BotPlugins', false)) {
            return;
        }
        if (!ModuleManager::isEnabled('booking') && !ModuleManager::isConfigEnabled('booking')) {
            return;
        }

        BotPlugins::register('booking', [
            'priority' => 20,
            'audiences' => ['client'],
            'label' => 'bots.catalog.client.booking',
            'module' => 'booking',
            'cabinet_buttons' => static function (array $ctx): array {
                if (($ctx['audience'] ?? '') !== 'client') {
                    return [];
                }
                return [[[
                    'text' => t('bots.catalog.client.booking'),
                    'callback_data' => 'booking',
                ]]];
            },
            'handle_callback' => static function (array $ctx) {
                if (($ctx['audience'] ?? '') !== 'client') {
                    return null;
                }
                if ((string)($ctx['callback_data'] ?? '') !== 'booking') {
                    return null;
                }
                $url = self::bookingUrl();
                $text = $url !== ''
                    ? t('booking.bot.open', ['url' => $url])
                    : t('booking.bot.open_fallback');
                BotClient::replyUi($ctx, $text, [
                    'inline_keyboard' => [[
                        ['text' => t('bots.cabinet.refresh'), 'callback_data' => 'cabinet'],
                    ]],
                ]);
                return true;
            },
        ]);
    }

    private static function bookingUrl(): string
    {
        $public = trim((string)Config::get('app', 'public_host', ''));
        $public = preg_replace('#^https?://#i', '', $public) ?? $public;
        $public = rtrim($public, '/');
        if ($public === '' && class_exists('SiteHostsRepository', false) && SiteHostsRepository::tableReady()) {
            foreach (SiteHostsRepository::list(['enabled' => '1', 'limit' => 20])['items'] as $h) {
                $cand = strtolower(trim((string)($h['host'] ?? '')));
                if ($cand === '' || $cand === 'localhost' || str_starts_with($cand, 'freesaas.')) {
                    continue;
                }
                $public = $cand;
                break;
            }
        }
        if ($public === '') {
            return '';
        }
        // Storefront cart/booking wizard when booking module is on.
        return 'https://' . $public . '/cart';
    }
}
