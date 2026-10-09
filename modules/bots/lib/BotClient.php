<?php
declare(strict_types=1);

/**
 * Telegram + Max HTTP transport (oper-compatible behaviour, class API).
 */
final class BotClient
{
    public const TG_API = 'https://api.telegram.org';
    public const MAX_API = 'https://platform-api2.max.ru';
    public const MESSAGE_MAX = 4000;

    /** @var bool When true, sendMessage acts as sendUiMessage (webhook inbound session). */
    private static bool $uiActive = false;
    private static string $uiAudience = '';
    private static ?string $uiMessageId = null;

    /**
     * Scope webhook handling: any sendMessage from plugins (even old code) replaces prior UI.
     * Drain / voice workers must not call this — they keep history.
     */
    public static function beginUiSession(string $audience, ?string $messageId = null): void
    {
        self::$uiActive = true;
        self::$uiAudience = trim($audience);
        $mid = $messageId !== null ? trim($messageId) : '';
        self::$uiMessageId = ($mid !== '' && $mid !== '0') ? $mid : null;
    }

    public static function endUiSession(): void
    {
        self::$uiActive = false;
        self::$uiAudience = '';
        self::$uiMessageId = null;
    }

    private static function takeSessionMessageId(): ?string
    {
        $mid = self::$uiMessageId;
        self::$uiMessageId = null;
        return $mid;
    }

    /**
     * @param array<string,mixed>|null $replyMarkup Telegram-style inline_keyboard
     * @param string $parseMode Telegram parse_mode (HTML|Markdown|MarkdownV2) or empty
     * @return array{ok:bool,data:?array,error:string}
     */
    public static function sendMessage(
        string $platform,
        string $token,
        string $chatId,
        string $text,
        ?array $replyMarkup = null,
        string $parseMode = ''
    ): array {
        $platform = strtolower(trim($platform));
        $token = trim($token);
        $chatId = trim($chatId);
        if ($token === '' || $chatId === '') {
            return ['ok' => false, 'data' => null, 'error' => 'missing token or chat id'];
        }
        if (self::$uiActive) {
            return self::sendUiMessage(
                $platform,
                $token,
                $chatId,
                $text,
                $replyMarkup,
                $parseMode,
                self::takeSessionMessageId(),
                self::$uiAudience
            );
        }
        if ($platform === 'max') {
            return self::maxSendMessage($token, $chatId, $text, $replyMarkup);
        }
        return self::tgSendMessage($token, $chatId, $text, $replyMarkup, $parseMode);
    }

    /**
     * UI reply: delete previous bot menu messages, send new, track message_id (oper replace_previous).
     * Use for cabinet / plugin screens. Keep plain sendMessage for drain, transcripts, push.
     *
     * @param array<string,mixed>|null $replyMarkup
     * @return array{ok:bool,data:?array,error:string}
     */
    public static function sendUiMessage(
        string $platform,
        string $token,
        string $chatId,
        string $text,
        ?array $replyMarkup = null,
        string $parseMode = '',
        ?string $currentMessageId = null,
        string $audience = ''
    ): array {
        $platform = strtolower(trim($platform));
        $token = trim($token);
        $chatId = trim($chatId);
        $audience = trim($audience);
        if ($token === '' || $chatId === '') {
            return ['ok' => false, 'data' => null, 'error' => 'missing token or chat id'];
        }
        $cur = $currentMessageId !== null ? trim($currentMessageId) : '';
        if ($cur === '' || $cur === '0') {
            $currentMessageId = self::takeSessionMessageId();
        } elseif (self::$uiActive && self::$uiMessageId !== null && (string)self::$uiMessageId === $cur) {
            self::$uiMessageId = null;
        }
        if ($audience === '' && self::$uiActive) {
            $audience = self::$uiAudience;
        }
        if ($platform === 'max') {
            return self::maxSendUiReplace($token, $chatId, $text, $replyMarkup, $currentMessageId, $audience);
        }
        return self::tgSendUiReplace($token, $chatId, $text, $replyMarkup, $parseMode, $currentMessageId, $audience);
    }

    /**
     * Convenience: sendUiMessage from webhook/plugin ctx (audience, platform, token, chat_id, message_id).
     *
     * @param array<string,mixed> $ctx
     * @param array<string,mixed>|null $replyMarkup
     * @return array{ok:bool,data:?array,error:string}
     */
    public static function replyUi(array $ctx, string $text, ?array $replyMarkup = null, string $parseMode = ''): array
    {
        $mid = isset($ctx['message_id']) ? trim((string)$ctx['message_id']) : '';
        $audience = trim((string)($ctx['audience'] ?? ''));
        if ($audience === '' && self::$uiActive) {
            $audience = self::$uiAudience;
        }
        return self::sendUiMessage(
            (string)($ctx['platform'] ?? 'telegram'),
            (string)($ctx['token'] ?? ''),
            (string)($ctx['chat_id'] ?? ''),
            $text,
            $replyMarkup,
            $parseMode,
            $mid !== '' ? $mid : null,
            $audience
        );
    }

    /**
     * @return array{ok:bool,data:?array,error:string}
     */
    public static function deleteMessage(string $platform, string $token, string $chatId, string $messageId): array
    {
        $platform = strtolower(trim($platform));
        $token = trim($token);
        $chatId = trim($chatId);
        $messageId = trim($messageId);
        if ($token === '' || $messageId === '') {
            return ['ok' => false, 'data' => null, 'error' => 'missing token or message id'];
        }
        if ($platform === 'max') {
            return self::maxDeleteMessage($token, $messageId, $chatId);
        }
        return self::tgRequest($token, 'deleteMessage', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
        ]);
    }

    /**
     * Strip inline keyboard (and optionally blank text) when delete fails.
     *
     * @return array{ok:bool,data:?array,error:string}
     */
    public static function clearMessageKeyboard(
        string $platform,
        string $token,
        string $chatId,
        string $messageId,
        ?string $replacementText = null
    ): array {
        $platform = strtolower(trim($platform));
        $token = trim($token);
        $chatId = trim($chatId);
        $messageId = trim($messageId);
        if ($token === '' || $messageId === '') {
            return ['ok' => false, 'data' => null, 'error' => 'missing token or message id'];
        }
        if ($platform === 'max') {
            $body = [
                'attachments' => [],
                'format' => 'html',
            ];
            if ($replacementText !== null) {
                $body['text'] = $replacementText;
            }
            return self::maxEditMessage($token, $messageId, $body, $chatId);
        }
        $fields = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'reply_markup' => json_encode(['inline_keyboard' => []], JSON_UNESCAPED_UNICODE),
        ];
        if ($replacementText !== null) {
            $fields['text'] = $replacementText;
            return self::tgRequest($token, 'editMessageText', $fields);
        }
        return self::tgRequest($token, 'editMessageReplyMarkup', $fields);
    }

    /**
     * Telegram getFile → download to a temp path.
     *
     * @return array{ok:bool,path:string,ext:string,error:string}
     */
    public static function downloadTelegramFile(string $token, string $fileId, string $preferExt = 'ogg'): array
    {
        $token = trim($token);
        $fileId = trim($fileId);
        if ($token === '' || $fileId === '') {
            return ['ok' => false, 'path' => '', 'ext' => '', 'error' => 'missing token or file_id'];
        }
        $meta = self::tgRequest($token, 'getFile', ['file_id' => $fileId]);
        if (!$meta['ok'] || !is_array($meta['data']['result'] ?? null)) {
            return ['ok' => false, 'path' => '', 'ext' => '', 'error' => $meta['error'] !== '' ? $meta['error'] : 'getFile failed'];
        }
        $filePath = (string)($meta['data']['result']['file_path'] ?? '');
        if ($filePath === '') {
            return ['ok' => false, 'path' => '', 'ext' => '', 'error' => 'empty file_path'];
        }
        $ext = strtolower((string)pathinfo($filePath, PATHINFO_EXTENSION));
        if ($ext === '') {
            $ext = preg_replace('/[^a-z0-9]/', '', strtolower($preferExt)) ?: 'ogg';
        }
        $url = self::TG_API . '/file/bot' . $token . '/' . $filePath;
        $tmp = tempnam(sys_get_temp_dir(), 'botvoice_');
        if ($tmp === false) {
            return ['ok' => false, 'path' => '', 'ext' => '', 'error' => 'tempnam failed'];
        }
        $dest = $tmp . '.' . $ext;
        @unlink($tmp);
        $fp = fopen($dest, 'wb');
        if ($fp === false) {
            return ['ok' => false, 'path' => '', 'ext' => '', 'error' => 'fopen failed'];
        }
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 120,
        ]);
        $ok = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        fclose($fp);
        if ($ok === false) {
            @unlink($dest);
            return ['ok' => false, 'path' => '', 'ext' => '', 'error' => $err !== '' ? $err : 'download failed'];
        }
        return ['ok' => true, 'path' => $dest, 'ext' => $ext, 'error' => ''];
    }

    /**
     * Download arbitrary URL (Max attachment) to temp file.
     *
     * @return array{ok:bool,path:string,ext:string,error:string}
     */
    public static function downloadUrlToTemp(string $url, string $preferExt = 'ogg'): array
    {
        $url = trim($url);
        if ($url === '') {
            return ['ok' => false, 'path' => '', 'ext' => '', 'error' => 'empty url'];
        }
        $ext = preg_replace('/[^a-z0-9]/', '', strtolower($preferExt)) ?: 'ogg';
        $tmp = tempnam(sys_get_temp_dir(), 'botvoice_');
        if ($tmp === false) {
            return ['ok' => false, 'path' => '', 'ext' => '', 'error' => 'tempnam failed'];
        }
        $dest = $tmp . '.' . $ext;
        @unlink($tmp);
        $fp = fopen($dest, 'wb');
        if ($fp === false) {
            return ['ok' => false, 'path' => '', 'ext' => '', 'error' => 'fopen failed'];
        }
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);
        $ok = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        fclose($fp);
        if ($ok === false) {
            @unlink($dest);
            return ['ok' => false, 'path' => '', 'ext' => '', 'error' => $err !== '' ? $err : 'download failed'];
        }
        return ['ok' => true, 'path' => $dest, 'ext' => $ext, 'error' => ''];
    }

    /**
     * @return array{ok:bool,data:?array,error:string}
     */
    public static function answerCallback(string $platform, string $token, string $callbackId): array
    {
        $platform = strtolower(trim($platform));
        $token = trim($token);
        $callbackId = trim($callbackId);
        if ($token === '' || $callbackId === '') {
            return ['ok' => false, 'data' => null, 'error' => 'missing token or callback id'];
        }
        if ($platform === 'max') {
            return self::maxRequest($token, 'POST', '/answers', ['callback_id' => $callbackId], [
                'notification' => '',
            ]);
        }
        return self::tgRequest($token, 'answerCallbackQuery', [
            'callback_query_id' => $callbackId,
        ]);
    }

    /**
     * @param array<string,mixed>|null $replyMarkup
     * @return array{ok:bool,data:?array,error:string}
     */
    private static function tgSendUiReplace(
        string $token,
        string $chatId,
        string $text,
        ?array $replyMarkup,
        string $parseMode,
        ?string $currentMessageId,
        string $audience
    ): array {
        self::purgeEphemeral($audience, 'telegram', $token, $chatId, $currentMessageId, false);
        $chunks = self::chunkText($text);
        $last = array_pop($chunks);
        $lastRes = ['ok' => false, 'data' => null, 'error' => 'empty text'];
        foreach ($chunks as $chunk) {
            $res = self::tgSendOne($token, $chatId, (string)$chunk, null, $parseMode);
            if (!$res['ok']) {
                return $res;
            }
            $mid = self::extractMessageId('telegram', $res['data']);
            if ($mid !== null) {
                BotEphemeral::saveMessageId($audience, 'telegram', $chatId, $mid);
            }
            $lastRes = $res;
        }
        $res = self::tgSendOne($token, $chatId, (string)$last, $replyMarkup, $parseMode);
        if ($res['ok']) {
            $mid = self::extractMessageId('telegram', $res['data']);
            if ($mid !== null) {
                BotEphemeral::saveMessageId($audience, 'telegram', $chatId, $mid);
            }
        }
        return $res['ok'] ? $res : $lastRes;
    }

    /**
     * Max: send first, then delete old (less flicker), track mid + latest_menu fallback.
     *
     * @param array<string,mixed>|null $replyMarkup
     * @return array{ok:bool,data:?array,error:string}
     */
    private static function maxSendUiReplace(
        string $token,
        string $chatId,
        string $text,
        ?array $replyMarkup,
        ?string $currentMessageId,
        string $audience
    ): array {
        $latestMenu = BotEphemeral::takeLatestMenuMessageId($audience, 'max', $chatId);
        $toDelete = BotEphemeral::takeMessageIds($audience, 'max', $chatId);
        if ($latestMenu !== null) {
            $toDelete[] = $latestMenu;
        }
        $cur = $currentMessageId !== null ? trim($currentMessageId) : '';
        if ($cur !== '' && $cur !== '0') {
            $toDelete[] = $cur;
        }
        $toDelete = array_values(array_unique($toDelete));

        $res = self::maxSendMessage($token, $chatId, $text, $replyMarkup);
        $mid = self::extractMessageId('max', $res['data'] ?? null);

        foreach ($toDelete as $delMid) {
            if ($mid !== null && (string)$delMid === (string)$mid) {
                continue;
            }
            $del = self::maxDeleteMessage($token, $delMid, $chatId);
            if (!$del['ok']) {
                self::clearMessageKeyboard('max', $token, $chatId, $delMid, ' ');
            }
        }

        if ($mid !== null) {
            BotEphemeral::saveMessageId($audience, 'max', $chatId, $mid);
        }
        if ($cur !== '' && $cur !== '0') {
            BotEphemeral::setLatestMenuMessageId($audience, 'max', $chatId, $cur);
        }
        return $res;
    }

    private static function purgeEphemeral(
        string $audience,
        string $platform,
        string $token,
        string $chatId,
        ?string $alsoMessageId,
        bool $blankOnFail
    ): void {
        $ids = BotEphemeral::takeMessageIds($audience, $platform, $chatId);
        $also = $alsoMessageId !== null ? trim($alsoMessageId) : '';
        if ($also !== '' && $also !== '0') {
            $ids[] = $also;
        }
        foreach (array_values(array_unique($ids)) as $mid) {
            $del = self::deleteMessage($platform, $token, $chatId, $mid);
            if (!$del['ok']) {
                self::clearMessageKeyboard(
                    $platform,
                    $token,
                    $chatId,
                    $mid,
                    $blankOnFail || $platform === 'max' ? ' ' : null
                );
            }
        }
    }

    /** @param array<string,mixed>|null $apiData */
    public static function extractMessageId(string $platform, ?array $apiData): ?string
    {
        if ($apiData === null) {
            return null;
        }
        if (strtolower($platform) === 'max') {
            foreach ([
                ['message', 'body', 'mid'],
                ['body', 'mid'],
                ['message', 'mid'],
                ['message', 'id'],
                ['message', 'body', 'id'],
                ['body', 'id'],
                ['mid'],
                ['id'],
            ] as $path) {
                $cur = $apiData;
                $ok = true;
                foreach ($path as $k) {
                    if (!is_array($cur) || !isset($cur[$k])) {
                        $ok = false;
                        break;
                    }
                    $cur = $cur[$k];
                }
                if ($ok && (is_string($cur) || is_int($cur))) {
                    $s = trim((string)$cur);
                    if ($s !== '' && $s !== '0') {
                        return $s;
                    }
                }
            }
            return null;
        }
        $mid = $apiData['result']['message_id'] ?? null;
        if ($mid === null || $mid === '') {
            return null;
        }
        return trim((string)$mid);
    }

    /**
     * @param array<string,mixed>|null $replyMarkup
     * @return array{ok:bool,data:?array,error:string}
     */
    private static function tgSendOne(
        string $token,
        string $chatId,
        string $text,
        ?array $replyMarkup,
        string $parseMode
    ): array {
        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
            'disable_web_page_preview' => true,
        ];
        if ($parseMode !== '') {
            $payload['parse_mode'] = $parseMode;
        }
        if ($replyMarkup !== null) {
            $payload['reply_markup'] = json_encode($replyMarkup, JSON_UNESCAPED_UNICODE);
        }
        return self::tgRequest($token, 'sendMessage', $payload);
    }

    /**
     * @param array<string,mixed>|null $replyMarkup
     * @return array{ok:bool,data:?array,error:string}
     */
    private static function tgSendMessage(
        string $token,
        string $chatId,
        string $text,
        ?array $replyMarkup,
        string $parseMode = ''
    ): array {
        $chunks = self::chunkText($text);
        $last = array_pop($chunks);
        foreach ($chunks as $chunk) {
            $res = self::tgSendOne($token, $chatId, (string)$chunk, null, $parseMode);
            if (!$res['ok']) {
                return $res;
            }
        }
        return self::tgSendOne($token, $chatId, (string)$last, $replyMarkup, $parseMode);
    }

    /**
     * @return array{ok:bool,data:?array,error:string}
     */
    private static function maxDeleteMessage(string $token, string $messageId, string $userId): array
    {
        $idVariants = [(string)$messageId];
        if (strpos($messageId, 'mid.') === 0) {
            $idVariants[] = substr($messageId, 4);
        }
        $queries = [
            ['message_id' => '', 'user_id' => $userId],
            ['message_id' => ''],
        ];
        foreach (array_values(array_unique($idVariants)) as $idv) {
            foreach ($queries as $base) {
                $q = $base;
                $q['message_id'] = (string)$idv;
                if (($q['user_id'] ?? '') === '') {
                    unset($q['user_id']);
                }
                $res = self::maxRequest($token, 'DELETE', '/messages', $q, null);
                if ($res['ok']) {
                    return $res;
                }
            }
            $res = self::maxRequest($token, 'DELETE', '/messages/' . rawurlencode((string)$idv), [
                'user_id' => $userId,
            ], null);
            if ($res['ok']) {
                return $res;
            }
            $res = self::maxRequest($token, 'DELETE', '/messages/' . rawurlencode((string)$idv), [], null);
            if ($res['ok']) {
                return $res;
            }
        }
        return ['ok' => false, 'data' => null, 'error' => 'max delete failed'];
    }

    /**
     * @param array<string,mixed> $body
     * @return array{ok:bool,data:?array,error:string}
     */
    private static function maxEditMessage(string $token, string $messageId, array $body, string $userId): array
    {
        $idVariants = [(string)$messageId];
        if (strpos($messageId, 'mid.') === 0) {
            $idVariants[] = substr($messageId, 4);
        }
        foreach (array_values(array_unique($idVariants)) as $idv) {
            $res = self::maxRequest($token, 'PUT', '/messages', [
                'message_id' => (string)$idv,
                'user_id' => $userId,
            ], $body);
            if ($res['ok']) {
                return $res;
            }
            $res = self::maxRequest($token, 'PUT', '/messages', [
                'message_id' => (string)$idv,
            ], $body);
            if ($res['ok']) {
                return $res;
            }
            $res = self::maxRequest($token, 'PUT', '/messages/' . rawurlencode((string)$idv), [
                'user_id' => $userId,
            ], $body);
            if ($res['ok']) {
                return $res;
            }
        }
        return ['ok' => false, 'data' => null, 'error' => 'max edit failed'];
    }

    /**
     * @param array<string,mixed> $fields
     * @return array{ok:bool,data:?array,error:string}
     */
    private static function tgRequest(string $token, string $method, array $fields): array
    {
        $url = self::TG_API . '/bot' . $token . '/' . $method;
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $fields,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
        ]);
        $response = curl_exec($ch);
        if ($response === false) {
            $err = curl_error($ch);
            curl_close($ch);
            return ['ok' => false, 'data' => null, 'error' => $err !== '' ? $err : 'curl failed'];
        }
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $data = json_decode((string)$response, true);
        if (!is_array($data)) {
            return ['ok' => false, 'data' => null, 'error' => 'invalid telegram json http=' . $http];
        }
        if (empty($data['ok'])) {
            $desc = (string)($data['description'] ?? 'telegram error');
            return ['ok' => false, 'data' => $data, 'error' => $desc];
        }
        return ['ok' => true, 'data' => $data, 'error' => ''];
    }

    /**
     * @param array<string,mixed>|null $replyMarkup
     * @return array{ok:bool,data:?array,error:string}
     */
    private static function maxSendMessage(string $token, string $userId, string $text, ?array $replyMarkup): array
    {
        $body = [
            'text' => $text,
            'format' => 'html',
        ];
        $attachments = self::maxConvertInlineKeyboard($replyMarkup);
        if ($attachments !== []) {
            $body['attachments'] = $attachments;
        }
        return self::maxRequest($token, 'POST', '/messages', ['user_id' => $userId], $body);
    }

    /**
     * @param array<string,scalar> $query
     * @param array<string,mixed>|null $body
     * @return array{ok:bool,data:?array,error:string}
     */
    private static function maxRequest(string $token, string $method, string $path, array $query, ?array $body): array
    {
        $url = self::MAX_API . $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }
        $ch = curl_init();
        $headers = [
            'Authorization: ' . $token,
            'Content-Type: application/json',
        ];
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
        }
        self::applyMaxSsl($ch);
        $response = curl_exec($ch);
        if ($response === false) {
            $err = curl_error($ch);
            curl_close($ch);
            return ['ok' => false, 'data' => null, 'error' => $err !== '' ? $err : 'curl failed'];
        }
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $data = ($response !== '' ? json_decode((string)$response, true) : null);
        if ($http < 200 || $http >= 300) {
            $snippet = is_string($response) ? substr($response, 0, 300) : '';
            return ['ok' => false, 'data' => is_array($data) ? $data : null, 'error' => 'max http ' . $http . ' ' . $snippet];
        }
        return ['ok' => true, 'data' => is_array($data) ? $data : [], 'error' => ''];
    }

    /** @param resource $ch */
    private static function applyMaxSsl($ch): void
    {
        if (BotsConfig::maxSslInsecure()) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            return;
        }
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        $bundle = base_path('modules/bots/certs/max_api_ca_bundle.pem');
        if (is_readable($bundle)) {
            curl_setopt($ch, CURLOPT_CAINFO, $bundle);
        }
    }

    /**
     * @param array<string,mixed>|null $replyMarkup
     * @return list<array<string,mixed>>
     */
    private static function maxConvertInlineKeyboard(?array $replyMarkup): array
    {
        if ($replyMarkup === null || empty($replyMarkup['inline_keyboard']) || !is_array($replyMarkup['inline_keyboard'])) {
            return [];
        }
        $buttons = [];
        foreach ($replyMarkup['inline_keyboard'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $line = [];
            foreach ($row as $btn) {
                if (!is_array($btn)) {
                    continue;
                }
                $text = trim((string)($btn['text'] ?? ''));
                if ($text === '') {
                    continue;
                }
                $payload = (string)($btn['callback_data'] ?? $btn['url'] ?? '');
                $line[] = [
                    'type' => isset($btn['url']) ? 'link' : 'callback',
                    'text' => $text,
                    'payload' => $payload,
                    'url' => (string)($btn['url'] ?? ''),
                ];
            }
            if ($line !== []) {
                $buttons[] = $line;
            }
        }
        if ($buttons === []) {
            return [];
        }
        return [[
            'type' => 'inline_keyboard',
            'payload' => ['buttons' => $buttons],
        ]];
    }

    /** @return list<string> */
    private static function chunkText(string $text): array
    {
        $max = self::MESSAGE_MAX;
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            $len = mb_strlen($text);
            if ($len <= $max) {
                return [$text];
            }
            $chunks = [];
            $pos = 0;
            while ($pos < $len) {
                $piece = mb_substr($text, $pos, $max);
                $lastNl = mb_strrpos($piece, "\n");
                if ($lastNl !== false && $lastNl > (int)($max * 0.5)) {
                    $chunks[] = mb_substr($piece, 0, $lastNl + 1);
                    $pos += $lastNl + 1;
                } else {
                    $chunks[] = $piece;
                    $pos += mb_strlen($piece);
                }
            }
            return $chunks;
        }
        if (strlen($text) <= $max) {
            return [$text];
        }
        return str_split($text, $max);
    }
}
