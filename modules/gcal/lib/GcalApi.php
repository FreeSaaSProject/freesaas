<?php
declare(strict_types=1);

final class GcalApi
{
    private const BASE = 'https://www.googleapis.com/calendar/v3';

    public static function ensureAccessToken(int $userId): ?string
    {
        $row = GcalSyncRepository::getToken($userId);
        if (!$row) {
            return null;
        }
        $access = (string)$row['ACCESS_TOKEN'];
        $expires = (int)$row['EXPIRES_AT'];
        if ($access !== '' && $expires > time() + 30) {
            return $access;
        }
        $refresh = trim((string)$row['REFRESH_TOKEN']);
        if ($refresh === '') {
            return null;
        }
        $tok = GcalOAuth::refresh($refresh);
        if ($tok === null) {
            return null;
        }
        GcalSyncRepository::upsertToken($userId, $tok);
        return $tok['access_token'];
    }

    /**
     * @return list<array{id:string,summary:string,primary:bool}>
     */
    public static function listCalendars(int $userId): array
    {
        $token = self::ensureAccessToken($userId);
        if ($token === null) {
            return [];
        }
        $res = self::request('GET', '/users/me/calendarList', $token);
        if ($res === null || empty($res['items']) || !is_array($res['items'])) {
            return [];
        }
        $out = [];
        foreach ($res['items'] as $it) {
            if (!is_array($it) || empty($it['id'])) {
                continue;
            }
            $out[] = [
                'id' => (string)$it['id'],
                'summary' => (string)($it['summary'] ?? $it['id']),
                'primary' => !empty($it['primary']),
            ];
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed>|null
     */
    public static function insertEvent(int $userId, string $calendarId, array $body): ?array
    {
        $token = self::ensureAccessToken($userId);
        if ($token === null || $calendarId === '') {
            return null;
        }
        return self::request(
            'POST',
            '/calendars/' . rawurlencode($calendarId) . '/events',
            $token,
            $body
        );
    }

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed>|null
     */
    public static function patchEvent(int $userId, string $calendarId, string $eventId, array $body): ?array
    {
        $token = self::ensureAccessToken($userId);
        if ($token === null || $calendarId === '' || $eventId === '') {
            return null;
        }
        return self::request(
            'PATCH',
            '/calendars/' . rawurlencode($calendarId) . '/events/' . rawurlencode($eventId),
            $token,
            $body
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function moveEvent(
        int $userId,
        string $sourceCalendarId,
        string $eventId,
        string $destCalendarId
    ): ?array {
        $token = self::ensureAccessToken($userId);
        if ($token === null || $sourceCalendarId === '' || $eventId === '' || $destCalendarId === '') {
            return null;
        }
        $path = '/calendars/' . rawurlencode($sourceCalendarId)
            . '/events/' . rawurlencode($eventId)
            . '/move?destination=' . rawurlencode($destCalendarId);
        return self::request('POST', $path, $token);
    }

    public static function deleteEvent(int $userId, string $calendarId, string $eventId): bool
    {
        $token = self::ensureAccessToken($userId);
        if ($token === null || $calendarId === '' || $eventId === '') {
            return false;
        }
        $res = self::request(
            'DELETE',
            '/calendars/' . rawurlencode($calendarId) . '/events/' . rawurlencode($eventId),
            $token,
            null,
            true
        );
        return $res !== null;
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function getEvent(int $userId, string $calendarId, string $eventId): ?array
    {
        $token = self::ensureAccessToken($userId);
        if ($token === null || $calendarId === '' || $eventId === '') {
            return null;
        }
        return self::request(
            'GET',
            '/calendars/' . rawurlencode($calendarId) . '/events/' . rawurlencode($eventId),
            $token
        );
    }

    /**
     * @param array<string,mixed>|null $body
     * @return array<string,mixed>|null
     */
    private static function request(
        string $method,
        string $path,
        string $token,
        ?array $body = null,
        bool $allowEmpty = false
    ): ?array {
        $url = self::BASE . $path;
        $ch = curl_init($url);
        $headers = [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
        ];
        $opts = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 45,
        ];
        if ($body !== null) {
            $json = json_encode($body, JSON_UNESCAPED_UNICODE);
            $headers[] = 'Content-Type: application/json';
            $opts[CURLOPT_POSTFIELDS] = $json;
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($raw === false) {
            return null;
        }
        if ($allowEmpty && ($code === 204 || $code === 200 || $code === 410)) {
            return [];
        }
        if ($code >= 400) {
            return null;
        }
        if ($raw === '' || $raw === 'null') {
            return $allowEmpty ? [] : null;
        }
        $data = json_decode((string)$raw, true);
        return is_array($data) ? $data : null;
    }
}
