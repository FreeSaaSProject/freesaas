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

    /**
     * @param array<string,mixed>|null $replyMarkup Telegram-style inline_keyboard
     * @return array{ok:bool,data:?array,error:string}
     */
    public static function sendMessage(
        string $platform,
        string $token,
        string $chatId,
        string $text,
        ?array $replyMarkup = null
    ): array {
        $platform = strtolower(trim($platform));
        $token = trim($token);
        $chatId = trim($chatId);
        if ($token === '' || $chatId === '') {
            return ['ok' => false, 'data' => null, 'error' => 'missing token or chat id'];
        }
        if ($platform === 'max') {
            return self::maxSendMessage($token, $chatId, $text, $replyMarkup);
        }
        return self::tgSendMessage($token, $chatId, $text, $replyMarkup);
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
    private static function tgSendMessage(string $token, string $chatId, string $text, ?array $replyMarkup): array
    {
        $chunks = self::chunkText($text);
        $last = array_pop($chunks);
        foreach ($chunks as $chunk) {
            $res = self::tgRequest($token, 'sendMessage', [
                'chat_id' => $chatId,
                'text' => $chunk,
                'disable_web_page_preview' => true,
            ]);
            if (!$res['ok']) {
                return $res;
            }
        }
        $payload = [
            'chat_id' => $chatId,
            'text' => (string)$last,
            'disable_web_page_preview' => true,
        ];
        if ($replyMarkup !== null) {
            $payload['reply_markup'] = json_encode($replyMarkup, JSON_UNESCAPED_UNICODE);
        }
        return self::tgRequest($token, 'sendMessage', $payload);
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
