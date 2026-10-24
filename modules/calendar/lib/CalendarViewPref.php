<?php
declare(strict_types=1);

/**
 * Remember day vs week calendar mode (oper dayShowEnabled cookie parity).
 */
final class CalendarViewPref
{
    private const COOKIE = 'freesaas_calendar_view';
    private const TTL = 7 * 86400;

    /**
     * @return array{mode:string,date:string}|null
     */
    public static function get(): ?array
    {
        $raw = (string)($_COOKIE[self::COOKIE] ?? '');
        if ($raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }
        $mode = (string)($data['mode'] ?? '');
        if ($mode !== 'day' && $mode !== 'week') {
            return null;
        }
        $date = trim((string)($data['date'] ?? ''));
        if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = date('Y-m-d', DemoMode::now());
        }
        return ['mode' => $mode, 'date' => $date];
    }

    public static function remember(string $mode, string $date): void
    {
        if ($mode !== 'day' && $mode !== 'week') {
            return;
        }
        $date = trim($date);
        if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = date('Y-m-d', DemoMode::now());
        }
        $payload = json_encode(['mode' => $mode, 'date' => $date], JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            return;
        }
        $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        setcookie(self::COOKIE, $payload, [
            'expires' => time() + self::TTL,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[self::COOKIE] = $payload;
    }

    /** Target URL for menu / bare /calendar entry from saved preference. */
    public static function homeUrl(): string
    {
        $pref = self::get();
        if ($pref === null || $pref['mode'] === 'week') {
            $date = $pref['date'] ?? date('Y-m-d', DemoMode::now());
            return '/calendar/week/' . rawurlencode($date);
        }
        return '/calendar/day/' . rawurlencode($pref['date']);
    }
}
