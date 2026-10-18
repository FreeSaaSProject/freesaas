<?php
declare(strict_types=1);

/** Lightweight Max API helpers for diagnose / spike (no secrets in responses). */
final class MaxGroupSpike
{
    public const API = 'https://platform-api2.max.ru';

    /**
     * @return array{ok:bool,data:?array,error:string,http:int}
     */
    public static function request(string $token, string $method, string $path, array $query = [], ?array $body = null): array
    {
        $token = trim($token);
        if ($token === '') {
            return ['ok' => false, 'data' => null, 'error' => 'no token', 'http' => 0];
        }
        $url = self::API . $path;
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
            CURLOPT_TIMEOUT => 25,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
        }
        $response = curl_exec($ch);
        if ($response === false) {
            $err = curl_error($ch);
            curl_close($ch);
            return ['ok' => false, 'data' => null, 'error' => $err !== '' ? $err : 'curl failed', 'http' => 0];
        }
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $data = $response !== '' ? json_decode((string)$response, true) : null;
        if ($http < 200 || $http >= 300) {
            $snippet = is_string($response) ? substr($response, 0, 300) : '';
            return ['ok' => false, 'data' => is_array($data) ? $data : null, 'error' => 'http ' . $http . ' ' . $snippet, 'http' => $http];
        }
        return ['ok' => true, 'data' => is_array($data) ? $data : [], 'error' => '', 'http' => $http];
    }

    /** @return array<string,mixed> */
    public static function diagnose(): array
    {
        $token = AssistantGroupsConfig::maxToken();
        $me = self::request($token, 'GET', '/me');
        $subs = self::request($token, 'GET', '/subscriptions');
        $chats = [];
        foreach (AssistantGroupsConfig::groupChatIds() as $cid) {
            $mem = self::request($token, 'GET', '/chats/' . rawurlencode($cid) . '/members/me');
            $chats[] = [
                'chat_id' => $cid,
                'ok' => $mem['ok'],
                'is_admin' => !empty($mem['data']['is_admin']),
                'permissions' => is_array($mem['data']['permissions'] ?? null) ? $mem['data']['permissions'] : [],
                'error' => $mem['error'],
            ];
        }
        $botUser = is_array($me['data'] ?? null) ? $me['data'] : [];
        return [
            'ok' => $me['ok'],
            'has_token' => $token !== '',
            'token_source' => AssistantGroupsConfig::hasOwnToken() ? 'assistant_groups' : 'bots.staff_max',
            'bot' => [
                'user_id' => (int)($botUser['user_id'] ?? 0),
                'username' => (string)($botUser['username'] ?? ''),
                'name' => (string)($botUser['first_name'] ?? $botUser['name'] ?? ''),
                'is_bot' => !empty($botUser['is_bot']),
            ],
            'subscriptions_count' => is_array($subs['data']['subscriptions'] ?? null)
                ? count($subs['data']['subscriptions'])
                : 0,
            'group_enabled' => AssistantGroupsConfig::isEnabled(),
            'require_mention' => AssistantGroupsConfig::requireMention(),
            'group_ops' => AssistantGroupsConfig::groupOpsPatterns(),
            'chats' => $chats,
            'error' => $me['ok'] ? '' : $me['error'],
        ];
    }
}
