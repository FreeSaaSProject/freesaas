<?php
declare(strict_types=1);

final class GcalConfig
{
    public static function enabled(): bool
    {
        return (int)Config::get('gcal', 'enabled', 0) === 1;
    }

    public static function clientId(): string
    {
        return trim((string)Config::get('gcal', 'client_id', ''));
    }

    public static function clientSecret(): string
    {
        return trim((string)Config::get('gcal', 'client_secret', ''));
    }

    public static function redirectUri(): string
    {
        $u = trim((string)Config::get('gcal', 'redirect_uri', ''));
        if ($u !== '') {
            return $u;
        }
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
        return $scheme . '://' . $host . '/gcal/oauth';
    }

    public static function workdayStart(): string
    {
        $v = trim((string)Config::get('gcal', 'workday_start', '09:00'));
        return preg_match('/^\d{1,2}:\d{2}$/', $v) ? $v : '09:00';
    }

    public static function workdayEnd(): string
    {
        $v = trim((string)Config::get('gcal', 'workday_end', '18:00'));
        return preg_match('/^\d{1,2}:\d{2}$/', $v) ? $v : '18:00';
    }

    public static function webhookSecret(): string
    {
        return trim((string)Config::get('gcal', 'webhook_secret', ''));
    }

    public static function appConfigured(): bool
    {
        return self::clientId() !== '' && self::clientSecret() !== '';
    }

    /**
     * @return array{optional:list<int>,required:list<int>,forbidden:list<int>}
     */
    public static function statusPolicyGroups(): array
    {
        static $cache = null;
        if (is_array($cache)) {
            return $cache;
        }
        $out = ['optional' => [], 'required' => [], 'forbidden' => []];
        if (!Database::tableExists('base_news_statuses')) {
            $cache = $out;
            return $out;
        }
        try {
            $st = Database::pdo()->query('SELECT ID, DEADLINE_POLICY FROM base_news_statuses');
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $id = (int)$r['ID'];
                if ($id <= 0) {
                    continue;
                }
                $p = trim((string)($r['DEADLINE_POLICY'] ?? 'optional'));
                if (!isset($out[$p])) {
                    $p = 'optional';
                }
                $out[$p][] = $id;
            }
        } catch (Throwable $e) {
            // column missing on older DBs
        }
        $cache = $out;
        return $out;
    }

    /** @return list<int> */
    public static function activeStatusIds(): array
    {
        $g = self::statusPolicyGroups();
        $a = array_merge($g['optional'], $g['required']);
        return $a !== [] ? array_values(array_unique($a)) : [1, 4, 6];
    }

    /** @return list<int> */
    public static function terminalStatusIds(): array
    {
        $f = self::statusPolicyGroups()['forbidden'];
        return $f !== [] ? array_values(array_unique($f)) : [99, 5];
    }

    public static function isTerminalStatus(int $status): bool
    {
        return in_array($status, self::terminalStatusIds(), true);
    }

    public static function isSyncedStatus(int $status): bool
    {
        return in_array($status, self::activeStatusIds(), true)
            || in_array($status, self::terminalStatusIds(), true);
    }
}
