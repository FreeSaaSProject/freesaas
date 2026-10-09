<?php
declare(strict_types=1);

/** Public webhook handler for Telegram / Max (staff + client channels). */
final class BotWebhook
{
    public static function handle(string $platform, string $channel): void
    {
        $platform = strtolower(trim($platform));
        $channel = strtolower(trim($channel));
        if (!in_array($platform, BotsConfig::PLATFORMS, true) || !in_array($channel, BotsConfig::AUDIENCES, true)) {
            http_response_code(404);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'unknown channel']);
            return;
        }

        $cfg = BotsConfig::channel($channel, $platform);
        if ($cfg === null || $cfg['token'] === '') {
            http_response_code(503);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'token not configured']);
            return;
        }

        if (!self::verifySecret($platform, $cfg['webhook_secret'])) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'unauthorized']);
            return;
        }

        $raw = file_get_contents('php://input');
        $update = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($update)) {
            http_response_code(400);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'invalid json']);
            return;
        }

        try {
            if ($platform === 'max') {
                self::handleMax($cfg, $update);
            } else {
                self::handleTelegram($cfg, $update);
            }
        } catch (Throwable $e) {
            error_log('[bots.webhook] ' . $e->getMessage());
        }

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true]);
    }

    private static function verifySecret(string $platform, string $expected): bool
    {
        if ($expected === '') {
            // Allow empty secret only in local/dev; still accept to ease bring-up.
            return true;
        }
        if ($platform === 'max') {
            $got = (string)($_SERVER['HTTP_X_MAX_BOT_API_SECRET'] ?? '');
            return hash_equals($expected, $got);
        }
        $got = (string)($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '');
        return hash_equals($expected, $got);
    }

    /** @param array{audience:string,platform:string,token:string,webhook_secret:string} $cfg */
    private static function handleTelegram(array $cfg, array $update): void
    {
        if (isset($update['callback_query']) && is_array($update['callback_query'])) {
            $cq = $update['callback_query'];
            $chatId = (string)($cq['message']['chat']['id'] ?? $cq['from']['id'] ?? '');
            $data = (string)($cq['data'] ?? '');
            $callbackId = (string)($cq['id'] ?? '');
            $user = self::resolveUser($cfg['audience'], 'telegram', $chatId);
            if ($callbackId !== '') {
                BotClient::answerCallback('telegram', $cfg['token'], $callbackId);
            }
            $messageId = isset($cq['message']['message_id']) ? trim((string)$cq['message']['message_id']) : '';
            self::processInbound($cfg, 'telegram', $chatId, $user, 'callback', [
                'callback_data' => $data,
                'message_id' => $messageId !== '' ? $messageId : null,
                'raw' => $cq,
            ]);
            return;
        }

        $msg = is_array($update['message'] ?? null) ? $update['message'] : null;
        if ($msg === null) {
            return;
        }
        $chatId = (string)($msg['chat']['id'] ?? '');
        if ($chatId === '') {
            return;
        }

        if (isset($msg['contact']) && is_array($msg['contact'])) {
            $phone = (string)($msg['contact']['phone_number'] ?? '');
            self::withUiSession($cfg['audience'], null, static function () use ($cfg, $chatId, $phone): void {
                self::tryLinkPhone($cfg, 'telegram', $chatId, $phone);
                $user = self::resolveUser($cfg['audience'], 'telegram', $chatId);
                self::sendCabinetOrAuth($cfg, 'telegram', $chatId, $user);
            });
            return;
        }

        $text = trim((string)($msg['text'] ?? ''));
        $user = self::resolveUser($cfg['audience'], 'telegram', $chatId);

        if ($text !== '' && ($text[0] === '/' || mb_strtolower($text) === 'start')) {
            if (stripos($text, '/start') === 0 || mb_strtolower($text) === 'start') {
                self::withUiSession($cfg['audience'], null, static function () use ($cfg, $chatId, $user): void {
                    self::sendCabinetOrAuth($cfg, 'telegram', $chatId, $user);
                });
                return;
            }
        }

        if ($user === null && $text !== '') {
            $tel = BotIdentity::normalizePhone($text);
            if ($tel !== null) {
                self::withUiSession($cfg['audience'], null, static function () use ($cfg, $chatId, $tel): void {
                    self::tryLinkPhone($cfg, 'telegram', $chatId, $tel);
                    $user = self::resolveUser($cfg['audience'], 'telegram', $chatId);
                    self::sendCabinetOrAuth($cfg, 'telegram', $chatId, $user);
                });
                return;
            }
        }

        self::processInbound($cfg, 'telegram', $chatId, $user, $text !== '' ? 'text' : 'message', [
            'text' => $text,
            'raw' => $msg,
        ]);
    }

    /** @param array{audience:string,platform:string,token:string,webhook_secret:string} $cfg */
    private static function handleMax(array $cfg, array $update): void
    {
        // Max webhook shapes vary; support update_type + message / callback.
        $type = (string)($update['update_type'] ?? $update['type'] ?? '');
        $callback = is_array($update['callback'] ?? null) ? $update['callback'] : null;
        if ($callback !== null || $type === 'message_callback') {
            $cb = $callback ?? (is_array($update['payload'] ?? null) ? $update['payload'] : []);
            $msg = is_array($update['message'] ?? null) ? $update['message'] : null;
            if ($msg === null && isset($cb['message']) && is_array($cb['message'])) {
                $msg = $cb['message'];
            }
            $chatId = (string)(
                $msg['recipient']['user_id']
                ?? $cb['user']['user_id']
                ?? $cb['user_id']
                ?? $cb['from']['user_id']
                ?? $msg['sender']['user_id']
                ?? ''
            );
            $payload = (string)($cb['payload'] ?? $cb['callback_data'] ?? '');
            $callbackId = (string)($cb['callback_id'] ?? $cb['callbackId'] ?? $cb['id'] ?? '');
            $messageId = self::extractMaxMessageId($msg);
            $user = self::resolveUser($cfg['audience'], 'max', $chatId);
            if ($callbackId !== '') {
                BotClient::answerCallback('max', $cfg['token'], $callbackId);
            }
            self::processInbound($cfg, 'max', $chatId, $user, 'callback', [
                'callback_data' => $payload,
                'message_id' => $messageId,
                'raw' => $cb,
            ]);
            return;
        }

        $msg = is_array($update['message'] ?? null) ? $update['message'] : null;
        if ($msg === null && isset($update['payload']) && is_array($update['payload'])) {
            $msg = $update['payload'];
        }
        if ($msg === null) {
            return;
        }
        $chatId = (string)($msg['sender']['user_id'] ?? $msg['recipient']['user_id'] ?? $msg['user_id'] ?? '');
        if ($chatId === '') {
            return;
        }
        $text = trim((string)($msg['body']['text'] ?? $msg['text'] ?? ''));
        $user = self::resolveUser($cfg['audience'], 'max', $chatId);

        $contactPhone = (string)($msg['body']['contact']['vcf_phone'] ?? $msg['contact']['phone_number'] ?? '');
        if ($contactPhone !== '') {
            self::withUiSession($cfg['audience'], null, static function () use ($cfg, $chatId, $contactPhone): void {
                self::tryLinkPhone($cfg, 'max', $chatId, $contactPhone);
                $user = self::resolveUser($cfg['audience'], 'max', $chatId);
                self::sendCabinetOrAuth($cfg, 'max', $chatId, $user);
            });
            return;
        }

        if ($text !== '' && (stripos($text, '/start') === 0 || mb_strtolower($text) === 'start')) {
            self::withUiSession($cfg['audience'], null, static function () use ($cfg, $chatId, $user): void {
                self::sendCabinetOrAuth($cfg, 'max', $chatId, $user);
            });
            return;
        }
        if ($user === null && $text !== '') {
            $tel = BotIdentity::normalizePhone($text);
            if ($tel !== null) {
                self::withUiSession($cfg['audience'], null, static function () use ($cfg, $chatId, $tel): void {
                    self::tryLinkPhone($cfg, 'max', $chatId, $tel);
                    $user = self::resolveUser($cfg['audience'], 'max', $chatId);
                    self::sendCabinetOrAuth($cfg, 'max', $chatId, $user);
                });
                return;
            }
        }
        self::processInbound($cfg, 'max', $chatId, $user, $text !== '' ? 'text' : 'message', [
            'text' => $text,
            'raw' => $msg,
        ]);
    }

    /**
     * @param array{audience:string,platform:string,token:string,webhook_secret:string} $cfg
     * @param array<string,mixed>|null $user
     * @param array<string,mixed> $extra
     */
    private static function processInbound(
        array $cfg,
        string $platform,
        string $chatId,
        ?array $user,
        string $kind,
        array $extra
    ): void {
        $messageId = isset($extra['message_id']) ? trim((string)$extra['message_id']) : '';
        $mid = $messageId !== '' ? $messageId : null;
        self::withUiSession($cfg['audience'], $mid, static function () use ($cfg, $platform, $chatId, $user, $kind, $extra, $mid): void {
            $payload = [
                'audience' => $cfg['audience'],
                'platform' => $platform,
                'chat_id' => $chatId,
                'user' => $user,
                'kind' => $kind,
                'token' => $cfg['token'],
                'callback_data' => (string)($extra['callback_data'] ?? ''),
                'text' => (string)($extra['text'] ?? ''),
                'message_id' => $mid,
                'raw' => $extra['raw'] ?? null,
                'handled' => false,
            ];
            $payload = EventBus::emit('bots.inbound', $payload);
            if (!is_array($payload)) {
                $payload = [];
            }
            if (!empty($payload['handled'])) {
                return;
            }

            $payloadMid = isset($payload['message_id']) ? trim((string)$payload['message_id']) : '';
            $payloadMid = $payloadMid !== '' ? $payloadMid : $mid;

            if ($kind === 'callback' && (string)($payload['callback_data'] ?? '') === 'cabinet') {
                self::sendCabinetOrAuth($cfg, $platform, $chatId, $user, $payloadMid);
                return;
            }

            if ($user === null) {
                self::sendAuthPrompt($cfg, $platform, $chatId, $payloadMid);
                return;
            }

            $ctx = [
                'audience' => $cfg['audience'],
                'platform' => $platform,
                'chat_id' => $chatId,
                'user' => $user,
                'token' => $cfg['token'],
                'callback_data' => (string)($payload['callback_data'] ?? ''),
                'text' => (string)($payload['text'] ?? ''),
                'message_id' => $payloadMid,
                'raw' => $payload['raw'] ?? null,
            ];
            $handled = BotPlugins::dispatch($kind, $ctx);
            if (!$handled && $kind === 'callback' && (string)$ctx['callback_data'] === 'cabinet') {
                self::sendCabinetOrAuth($cfg, $platform, $chatId, $user, $payloadMid);
                return;
            }
            if (!$handled && ($kind === 'text' || $kind === 'callback')) {
                self::sendCabinetOrAuth($cfg, $platform, $chatId, $user, $payloadMid);
            }
        });
    }

    /** @param callable():void $fn */
    private static function withUiSession(string $audience, ?string $messageId, callable $fn): void
    {
        BotClient::beginUiSession($audience, $messageId);
        try {
            $fn();
        } finally {
            BotClient::endUiSession();
        }
    }

    /**
     * @param array{audience:string,platform:string,token:string,webhook_secret:string} $cfg
     * @param array<string,mixed>|null $user
     */
    private static function sendCabinetOrAuth(
        array $cfg,
        string $platform,
        string $chatId,
        ?array $user,
        ?string $messageId = null
    ): void {
        if ($user === null) {
            self::sendAuthPrompt($cfg, $platform, $chatId, $messageId);
            return;
        }
        $name = (string)($user['name'] ?? '');
        $text = t('bots.cabinet.hello', ['name' => $name !== '' ? $name : '#' . (int)($user['id'] ?? 0)]);
        $markup = BotPlugins::collectCabinet([
            'audience' => $cfg['audience'],
            'platform' => $platform,
            'user' => $user,
            'chat_id' => $chatId,
        ]);
        BotClient::sendUiMessage(
            $platform,
            $cfg['token'],
            $chatId,
            $text,
            $markup,
            '',
            $messageId,
            $cfg['audience']
        );
    }

    /** @param array{audience:string,platform:string,token:string,webhook_secret:string} $cfg */
    private static function sendAuthPrompt(
        array $cfg,
        string $platform,
        string $chatId,
        ?string $messageId = null
    ): void {
        $text = t('bots.auth.prompt');
        $markup = [
            'inline_keyboard' => [[
                ['text' => t('bots.auth.send_phone_hint'), 'callback_data' => 'auth_hint'],
            ]],
        ];
        // Reply keyboard contact request (Telegram); Max falls back to text phone.
        if ($platform === 'telegram') {
            $markup = [
                'keyboard' => [[
                    ['text' => t('bots.auth.share_contact'), 'request_contact' => true],
                ]],
                'resize_keyboard' => true,
                'one_time_keyboard' => true,
            ];
        }
        BotClient::sendUiMessage(
            $platform,
            $cfg['token'],
            $chatId,
            $text,
            $markup,
            '',
            $messageId,
            $cfg['audience']
        );
    }

    /** @param array{audience:string,platform:string,token:string,webhook_secret:string} $cfg */
    private static function tryLinkPhone(array $cfg, string $platform, string $chatId, string $phoneRaw): void
    {
        $tel = BotIdentity::normalizePhone($phoneRaw);
        if ($tel === null) {
            BotClient::sendUiMessage(
                $platform,
                $cfg['token'],
                $chatId,
                t('bots.auth.bad_phone'),
                null,
                '',
                null,
                $cfg['audience']
            );
            return;
        }
        if ($cfg['audience'] === 'client') {
            $row = BotIdentity::findClientByPhone($tel);
            if ($row === null) {
                BotClient::sendUiMessage(
                    $platform,
                    $cfg['token'],
                    $chatId,
                    t('bots.auth.client_not_found'),
                    null,
                    '',
                    null,
                    $cfg['audience']
                );
                return;
            }
            BotIdentity::bindClient((int)$row['id'], $platform, $chatId);
            BotClient::sendUiMessage(
                $platform,
                $cfg['token'],
                $chatId,
                t('bots.auth.linked'),
                null,
                '',
                null,
                $cfg['audience']
            );
            return;
        }
        $row = BotIdentity::findStaffByPhone($tel);
        if ($row === null) {
            BotClient::sendUiMessage(
                $platform,
                $cfg['token'],
                $chatId,
                t('bots.auth.staff_not_found'),
                null,
                '',
                null,
                $cfg['audience']
            );
            return;
        }
        BotIdentity::bindStaff((int)$row['id'], $platform, $chatId);
        BotClient::sendUiMessage(
            $platform,
            $cfg['token'],
            $chatId,
            t('bots.auth.linked'),
            null,
            '',
            null,
            $cfg['audience']
        );
    }

    /** @param array<string,mixed>|null $msg */
    private static function extractMaxMessageId(?array $msg): ?string
    {
        if ($msg === null) {
            return null;
        }
        if (isset($msg['body']['mid']) && (string)$msg['body']['mid'] !== '') {
            return (string)$msg['body']['mid'];
        }
        if (isset($msg['mid']) && (string)$msg['mid'] !== '') {
            return (string)$msg['mid'];
        }
        if (isset($msg['id']) && (string)$msg['id'] !== '') {
            return (string)$msg['id'];
        }
        return null;
    }

    /** @return array<string,mixed>|null */
    private static function resolveUser(string $audience, string $platform, string $chatId): ?array
    {
        if ($audience === 'client') {
            return BotIdentity::findClientByChat($platform, $chatId);
        }
        return BotIdentity::findStaffByChat($platform, $chatId);
    }
}
