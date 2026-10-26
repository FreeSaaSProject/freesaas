<?php
declare(strict_types=1);

/**
 * Outbound IP of this host (ApiKey allowlists / bank callbacks).
 * Cached briefly — shared hosting egress is what the bank sees, not always SERVER_ADDR.
 */
final class BanksEgressIp
{
    private const CACHE_TTL = 3600;

    /** @return array{ip:string,source:string,cached:bool,hint:string,usable:bool,blocker:string} */
    public static function detect(): array
    {
        $cached = self::readCache();
        if ($cached !== null) {
            return self::pack($cached, 'cache', true);
        }
        $probes = [
            'https://api.ipify.org',
            'https://ifconfig.me/ip',
            'https://icanhazip.com',
        ];
        foreach ($probes as $url) {
            $ip = self::fetchIp($url);
            if ($ip !== '') {
                self::writeCache($ip);
                return self::pack($ip, $url, false);
            }
        }
        $fallback = self::localGuess();
        return self::pack(
            $fallback,
            $fallback !== '' ? 'local' : 'none',
            false
        );
    }

    /** Public routable IP suitable for bank ApiKey allowlists (not localhost/LAN). */
    public static function isUsable(?string $ip = null): bool
    {
        if ($ip === null) {
            $ip = (string)(self::detect()['ip'] ?? '');
        }
        $ip = trim($ip);
        if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }
        // Reject loopback, private, link-local, reserved.
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
        return true;
    }

    public static function assertUsableOrThrow(): void
    {
        $d = self::detect();
        if (!empty($d['usable'])) {
            return;
        }
        throw new RuntimeException(t('banks.setup.blocker_local_ip', [
            'ip' => ($d['ip'] !== '' ? $d['ip'] : '—'),
        ]));
    }

    /**
     * @return array{ip:string,source:string,cached:bool,hint:string,usable:bool,blocker:string}
     */
    private static function pack(string $ip, string $source, bool $cached): array
    {
        $usable = self::isUsable($ip);
        return [
            'ip' => $ip,
            'source' => $source,
            'cached' => $cached,
            'hint' => t('banks.settings.egress_ip_hint'),
            'usable' => $usable,
            'blocker' => $usable ? '' : t('banks.setup.blocker_local_ip', [
                'ip' => $ip !== '' ? $ip : '—',
            ]),
        ];
    }

    private static function fetchIp(string $url): string
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 4);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'FreeSaaS-banks-egress/1');
        $raw = (string)curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($http < 200 || $http >= 300) {
            return '';
        }
        $ip = trim($raw);
        if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6)) {
            return '';
        }
        return $ip;
    }

    private static function localGuess(): string
    {
        $server = trim((string)($_SERVER['SERVER_ADDR'] ?? ''));
        if ($server !== '' && filter_var($server, FILTER_VALIDATE_IP)) {
            return $server;
        }
        $host = gethostname();
        if (is_string($host) && $host !== '') {
            $resolved = gethostbyname($host);
            if (is_string($resolved) && $resolved !== $host && filter_var($resolved, FILTER_VALIDATE_IP)) {
                return $resolved;
            }
        }
        return '';
    }

    private static function cachePath(): string
    {
        $dir = base_path('var/cache');
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        return $dir . '/banks_egress_ip.json';
    }

    private static function readCache(): ?string
    {
        $path = self::cachePath();
        if (!is_readable($path)) {
            return null;
        }
        $raw = (string)@file_get_contents($path);
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }
        $ip = trim((string)($data['ip'] ?? ''));
        $at = (int)($data['at'] ?? 0);
        if ($ip === '' || $at < 1 || (time() - $at) > self::CACHE_TTL) {
            return null;
        }
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return null;
        }
        return $ip;
    }

    private static function writeCache(string $ip): void
    {
        @file_put_contents(
            self::cachePath(),
            (string)json_encode(['ip' => $ip, 'at' => time()], JSON_UNESCAPED_UNICODE)
        );
    }
}
