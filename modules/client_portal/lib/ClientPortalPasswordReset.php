<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
final class ClientPortalPasswordReset
{
    public const TTL = 3600;

    public static function tableReady(): bool
    {
        return Database::tableExists('base_client_portal_resets');
    }

    /**
     * Available recovery channels for UI (only those that can actually deliver).
     *
     * @return list<array{id:string,label:string,hint:string,url?:string,can_send:bool}>
     */
    public static function channelsForClient(?array $client): array
    {
        $channels = [];
        $hook = EventBus::emit('client_portal.password_reset.channels', [
            'client' => $client,
            'channels' => [],
        ]);
        foreach (($hook['channels'] ?? []) as $ch) {
            if (is_array($ch) && !empty($ch['id'])) {
                $channels[] = $ch;
            }
        }

        // Built-in: Telegram / Max deep links when bots configured (config-enabled,
        // even if bots runtime was skipped — storefront needs deep links without full boot).
        if (self::botsConfigReady()) {
            $tgToken = (string)(BotsConfig::channel('client', 'telegram')['token'] ?? '');
            $tgUser = self::resolveClientTelegramUsername();
            if ($tgToken !== '' && $tgUser !== '') {
                $channels[] = [
                    'id' => 'telegram',
                    'label' => t('client_portal.reset.channel.telegram'),
                    'hint' => t('client_portal.reset.channel.telegram_hint'),
                    'url' => 'https://t.me/' . $tgUser,
                    'can_send' => self::clientHasBotLink($client, 'telegram'),
                ];
            }
            $maxUrl = BotsConfig::clientMaxBotUrl();
            if ($maxUrl === '') {
                $maxUrl = trim((string)Config::get('bots', 'client_max_bot_url', ''));
            }
            $maxToken = (string)(BotsConfig::channel('client', 'max')['token'] ?? '');
            if ($maxToken !== '' && $maxUrl !== '') {
                $channels[] = [
                    'id' => 'max',
                    'label' => t('client_portal.reset.channel.max'),
                    'hint' => t('client_portal.reset.channel.max_hint'),
                    'url' => $maxUrl,
                    'can_send' => self::clientHasBotLink($client, 'max'),
                ];
            }
        }

        // Dedupe by id
        $seen = [];
        $out = [];
        foreach ($channels as $ch) {
            $id = (string)$ch['id'];
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $out[] = [
                'id' => $id,
                'label' => (string)($ch['label'] ?? $id),
                'hint' => (string)($ch['hint'] ?? ''),
                'url' => (string)($ch['url'] ?? ''),
                'can_send' => !empty($ch['can_send']),
            ];
        }
        return $out;
    }

    /**
     * Staff/agent diagnose snapshot (no secrets, no raw tokens).
     *
     * @return array<string,mixed>
     */
    public static function diagnose(?string $phone = null): array
    {
        $tgUser = self::resolveClientTelegramUsername();
        $maxUrl = class_exists('BotsConfig', false) ? BotsConfig::clientMaxBotUrl() : '';
        $tgToken = class_exists('BotsConfig', false)
            ? trim((string)(BotsConfig::channel('client', 'telegram')['token'] ?? ''))
            : '';
        $maxToken = class_exists('BotsConfig', false)
            ? trim((string)(BotsConfig::channel('client', 'max')['token'] ?? ''))
            : '';

        $out = [
            'table_ready' => self::tableReady(),
            'bots_enabled' => ModuleManager::isEnabled('bots'),
            'client_telegram' => [
                'has_token' => $tgToken !== '',
                'username' => $tgUser,
                'deep_link' => $tgUser !== '' ? ('https://t.me/' . $tgUser) : '',
            ],
            'client_max' => [
                'has_token' => $maxToken !== '',
                'bot_url' => $maxUrl,
            ],
            'channels_anonymous' => self::channelsForClient(null),
            'recent_resets' => [],
            'client' => null,
            'hints' => [],
        ];

        $botsSettingsHref = '/bots/settings';
        $botsClientsHref = '/bots/types/client';
        $botsSendsHref = '/bots/sends?audience=client';

        if (!self::tableReady()) {
            $out['hints'][] = self::hint(
                'need_migrate',
                t('client_portal.status.hint.need_migrate')
            );
        }
        if ($tgToken === '' && $maxToken === '') {
            $out['hints'][] = self::hint(
                'need_bot_tokens',
                t('client_portal.status.hint.need_bot_tokens'),
                $botsSettingsHref,
                t('client_portal.status.hint.open_bots_settings')
            );
        } elseif ($tgToken !== '' && $tgUser === '') {
            $out['hints'][] = self::hint(
                'need_telegram_username',
                t('client_portal.status.hint.need_telegram_username'),
                $botsSettingsHref,
                t('client_portal.status.hint.open_bots_settings')
            );
        }
        if ($maxToken !== '' && $maxUrl === '') {
            $out['hints'][] = self::hint(
                'need_max_url',
                t('client_portal.status.hint.need_max_url'),
                $botsSettingsHref,
                t('client_portal.status.hint.open_bots_settings')
            );
        }

        if (self::tableReady()) {
            $st = Database::pdo()->query(
                'SELECT ID AS id, CLIENT_ID AS client_id, CHANNEL AS channel,
                        DT_CREATE AS dt_create, DT_EXPIRE AS dt_expire, DT_USED AS dt_used, IP AS ip
                 FROM base_client_portal_resets ORDER BY ID DESC LIMIT 20'
            );
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $out['recent_resets'][] = [
                    'id' => (int)$row['id'],
                    'client_id' => (int)$row['client_id'],
                    'channel' => (string)$row['channel'],
                    'dt_create' => (int)$row['dt_create'],
                    'dt_expire' => (int)$row['dt_expire'],
                    'dt_used' => (int)$row['dt_used'],
                    'ip' => (string)$row['ip'],
                    'expired' => (int)$row['dt_expire'] < time(),
                    'used' => (int)$row['dt_used'] > 0,
                ];
            }
        }

        $phone = trim((string)$phone);
        if ($phone !== '' && class_exists('ClientsRepository', false)) {
            $digits = preg_replace('/\D+/', '', $phone) ?? '';
            $client = $digits !== '' ? ClientsRepository::findByPhoneDigits($digits) : null;
            if ($client === null) {
                $out['client'] = ['found' => false, 'digits' => $digits];
                $out['hints'][] = self::hint(
                    'phone_not_found',
                    t('client_portal.status.hint.phone_not_found')
                );
            } else {
                $cid = (int)$client['id'];
                $out['client'] = [
                    'found' => true,
                    'id' => $cid,
                    'enabled' => (int)($client['enabled'] ?? 0) === 1,
                    'name' => (string)($client['name'] ?? ''),
                    'telegram_linked' => self::clientHasBotLink($client, 'telegram'),
                    'max_linked' => self::clientHasBotLink($client, 'max'),
                    'channels' => self::channelsForClient($client),
                ];
                if (!(int)($client['enabled'] ?? 0)) {
                    $out['hints'][] = self::hint(
                        'client_disabled',
                        t('client_portal.status.hint.client_disabled'),
                        '/clients/' . $cid,
                        t('client_portal.status.hint.open_client')
                    );
                }
                if (!self::clientHasBotLink($client, 'telegram') && !self::clientHasBotLink($client, 'max')) {
                    $out['hints'][] = self::hint(
                        'need_bot_link',
                        t('client_portal.status.hint.need_bot_link'),
                        $botsClientsHref,
                        t('client_portal.status.hint.open_bots_clients')
                    );
                    $out['hints'][] = self::hint(
                        'check_sends',
                        t('client_portal.status.hint.check_sends'),
                        $botsSendsHref,
                        t('client_portal.status.hint.open_bots_sends')
                    );
                }
            }
        }

        return $out;
    }

    /**
     * @return array{code:string,message:string,href:string,link_label:string}
     */
    private static function hint(string $code, string $message, string $href = '', string $linkLabel = ''): array
    {
        return [
            'code' => $code,
            'message' => $message,
            'href' => $href,
            'link_label' => $linkLabel,
        ];
    }

    /**
     * @return array{ok:bool,error?:string,token?:string,reset_url?:string,channels?:list<array<string,mixed>>,delivered?:list<string>,message?:string,client_id?:int}
     */
    public static function start(string $phone, string $preferredChannel = '', bool $exposeUrl = false): array
    {
        if (!self::tableReady()) {
            return ['ok' => false, 'error' => t('client_portal.reset.err_unavailable')];
        }
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if ($digits === '' || !class_exists('ClientsRepository', false)) {
            return ['ok' => false, 'error' => t('client_portal.reset.err_phone')];
        }
        $client = ClientsRepository::findByPhoneDigits($digits);
        // Always return ok-ish to avoid account enumeration; still create only if found+enabled.
        if ($client === null || (int)($client['enabled'] ?? 0) !== 1) {
            return [
                'ok' => true,
                'channels' => self::channelsForClient(null),
                'delivered' => [],
                'message' => t('client_portal.reset.sent_generic'),
            ];
        }
        $token = bin2hex(random_bytes(16));
        $hash = hash('sha256', $token);
        $now = time();
        $st = Database::pdo()->prepare(
            'INSERT INTO base_client_portal_resets
             (CLIENT_ID, TOKEN_HASH, CHANNEL, DT_CREATE, DT_EXPIRE, DT_USED, IP)
             VALUES (?,?,?,?,?,?,?)'
        );
        $st->execute([
            (int)$client['id'],
            $hash,
            preg_replace('/[^a-z0-9_]/', '', $preferredChannel) ?? '',
            $now,
            $now + self::TTL,
            0,
            substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64),
        ]);

        $resetUrl = self::absoluteUrl('/cabinet/reset?token=' . rawurlencode($token));
        $channels = self::channelsForClient($client);
        $delivered = [];
        $deliveryErrors = [];

        // Push to linked messengers when possible.
        if (self::botsSendReady()) {
            $text = t('client_portal.reset.bot_message', ['url' => $resetUrl]);
            foreach (['telegram', 'max'] as $platform) {
                if ($preferredChannel !== '' && $preferredChannel !== $platform) {
                    continue;
                }
                if (!self::clientHasBotLink($client, $platform)) {
                    continue;
                }
                try {
                    BotSend::enqueue([
                        'text' => $text,
                        'client_id' => (int)$client['id'],
                        'audience' => 'client',
                        'platform' => $platform,
                        'name' => 'password_reset',
                        'dedupe_key' => 'cpr:' . (int)$client['id'] . ':' . $platform . ':' . substr($hash, 0, 12),
                    ]);
                    $delivered[] = $platform;
                } catch (Throwable $e) {
                    $deliveryErrors[] = $platform . ': ' . $e->getMessage();
                }
            }
        }

        // External SMS (or others) via EventBus.
        $notify = EventBus::emit('client_portal.password_reset.notify', [
            'client' => $client,
            'token' => $token,
            'reset_url' => $resetUrl,
            'channel' => $preferredChannel,
            'delivered' => $delivered,
            'error' => '',
        ]);
        if (is_array($notify['delivered'] ?? null)) {
            foreach ($notify['delivered'] as $d) {
                $d = (string)$d;
                if ($d !== '' && !in_array($d, $delivered, true)) {
                    $delivered[] = $d;
                }
            }
        }

        $out = [
            'ok' => true,
            'client_id' => (int)$client['id'],
            'channels' => $channels,
            'delivered' => $delivered,
            'delivery_errors' => $deliveryErrors,
            'message' => t('client_portal.reset.sent_generic'),
        ];
        if ($exposeUrl) {
            $out['token'] = $token;
            $out['reset_url'] = $resetUrl;
        }
        return $out;
    }

    /**
     * @return array{ok:bool,error?:string,client_id?:int}
     */
    public static function consume(string $token, string $newPassword): array
    {
        if (!self::tableReady()) {
            return ['ok' => false, 'error' => t('client_portal.reset.err_unavailable')];
        }
        $token = trim($token);
        if (strlen($token) < 16 || strlen($newPassword) < 6) {
            return ['ok' => false, 'error' => t('client_portal.reset.err_token')];
        }
        $hash = hash('sha256', $token);
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, CLIENT_ID AS client_id, DT_EXPIRE AS dt_expire, DT_USED AS dt_used
             FROM base_client_portal_resets WHERE TOKEN_HASH = ? LIMIT 1'
        );
        $st->execute([$hash]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['ok' => false, 'error' => t('client_portal.reset.err_token')];
        }
        if ((int)$row['dt_used'] > 0 || (int)$row['dt_expire'] < time()) {
            return ['ok' => false, 'error' => t('client_portal.reset.err_token')];
        }
        $clientId = (int)$row['client_id'];
        ClientPortalAuth::storePassword($clientId, $newPassword);
        Database::pdo()->prepare(
            'UPDATE base_client_portal_resets SET DT_USED = ? WHERE ID = ?'
        )->execute([time(), (int)$row['id']]);
        return ['ok' => true, 'client_id' => $clientId];
    }

    /** @param array<string,mixed>|null $client */
    private static function clientHasBotLink(?array $client, string $platform): bool
    {
        if ($client === null || !class_exists('BotLinksRepository', false) || !BotLinksRepository::tableReady()) {
            return false;
        }
        $row = BotLinksRepository::find(BotLinksRepository::SOURCE_CLIENT, (int)($client['id'] ?? 0));
        if ($row === null) {
            return false;
        }
        if ($platform === 'telegram') {
            return trim((string)($row['telegram_chat_id'] ?? '')) !== '';
        }
        if ($platform === 'max') {
            return trim((string)($row['max_user_id'] ?? '')) !== '';
        }
        return false;
    }

    private static function resolveClientTelegramUsername(): string
    {
        if (self::botsConfigReady()) {
            $u = BotsConfig::clientTelegramUsername();
            if ($u !== '') {
                return $u;
            }
        }
        $u = ltrim(trim((string)Config::get('bots', 'client_telegram_username', '')), '@');
        if ($u !== '') {
            return $u;
        }
        // Auto-discover once via getMe and cache in ini (admin runtime / when BotClient loaded).
        if (class_exists('BotClient', false)) {
            try {
                $me = BotClient::telegramGetMe('client');
                if (!empty($me['ok']) && $me['username'] !== '') {
                    Config::set('bots', 'client_telegram_username', $me['username']);
                    return $me['username'];
                }
            } catch (Throwable $e) {
                // ignore
            }
        }
        return '';
    }

    /** Bots enabled in config; load BotsConfig if needed (storefront may skip bots bootstrap). */
    private static function botsConfigReady(): bool
    {
        if (!ModuleManager::isConfigEnabled('bots') && !ModuleManager::isEnabled('bots')) {
            return false;
        }
        if (!class_exists('BotsConfig', false)) {
            $file = base_path('modules/bots/lib/BotsConfig.php');
            if (is_file($file)) {
                require_once $file;
            }
        }
        return class_exists('BotsConfig', false);
    }

    private static function botsSendReady(): bool
    {
        if (!self::botsConfigReady()) {
            return false;
        }
        if (!class_exists('BotSend', false)) {
            foreach ([
                'modules/bots/lib/BotLinksRepository.php',
                'modules/bots/lib/BotSendsRepository.php',
                'modules/bots/lib/BotSend.php',
            ] as $rel) {
                $file = base_path($rel);
                if (is_file($file)) {
                    require_once $file;
                }
            }
        }
        return class_exists('BotSend', false);
    }

    private static function absoluteUrl(string $path): string
    {
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
        $adminHost = strtolower(trim((string)Config::get('app', 'admin_host', '')));
        $public = trim((string)Config::get('app', 'public_host', ''));
        $public = preg_replace('#^https?://#i', '', $public) ?? $public;
        $public = rtrim($public, '/');
        $onAdmin = $adminHost !== '' && strcasecmp($host, $adminHost) === 0;
        $onAdmin = $onAdmin || str_starts_with(strtolower($host), 'freesaas.') || $host === 'localhost';
        if ($onAdmin) {
            if ($public !== '') {
                $host = $public;
                $https = true;
            } elseif (class_exists('SiteHostsRepository', false) && SiteHostsRepository::tableReady()) {
                foreach (SiteHostsRepository::list(['enabled' => '1', 'limit' => 20])['items'] as $h) {
                    $cand = strtolower(trim((string)($h['host'] ?? '')));
                    if ($cand === '' || $cand === 'localhost' || str_starts_with($cand, 'freesaas.')) {
                        continue;
                    }
                    $host = $cand;
                    $https = true;
                    break;
                }
            }
        }
        return ($https ? 'https://' : 'http://') . rtrim($host, '/') . (str_starts_with($path, '/') ? $path : '/' . $path);
    }
}
