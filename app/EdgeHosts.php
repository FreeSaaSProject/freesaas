<?php
declare(strict_types=1);

/**
 * Registry of edge workers hosts (base_edge_hosts): one row per machine running the
 * workers gateway. Product modules register the services they dispatch to
 * (EdgeHosts::registerService) and keep their own leases/queues.
 */
final class EdgeHosts
{
    public const TRANSPORT_INBOUND = 'inbound';

    /** @var array<string,array{module:string,label:string,max_inflight:int}> */
    private static array $services = [];

    /** @param array{module:string,label:string,max_inflight?:int} $def */
    public static function registerService(string $service, array $def): void
    {
        self::$services[$service] = [
            'module' => (string)$def['module'],
            'label' => (string)$def['label'],
            'max_inflight' => max(1, (int)($def['max_inflight'] ?? 20)),
        ];
    }

    /** @return array<string,array{module:string,label:string,max_inflight:int}> */
    public static function services(): array
    {
        return self::$services;
    }

    public static function available(): bool
    {
        return Database::tableExists('base_edge_hosts');
    }

    /** @return list<array<string,mixed>> */
    public static function all(): array
    {
        if (!self::available()) {
            return [];
        }
        $st = Database::pdo()->query('SELECT * FROM base_edge_hosts ORDER BY ID ASC');
        return $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        if ($id < 1 || !self::available()) {
            return null;
        }
        $st = Database::pdo()->prepare('SELECT * FROM base_edge_hosts WHERE ID = ? LIMIT 1');
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** Enabled hosts with the service switched on. @return list<array<string,mixed>> */
    public static function forService(string $service): array
    {
        return array_values(array_filter(
            self::all(),
            static fn (array $h): bool => (int)$h['ENABLED'] === 1 && self::serviceEnabled($h, $service)
        ));
    }

    /** @return array<string,array{enabled:bool,max_inflight:int}> */
    public static function plugins(array $host): array
    {
        $raw = json_decode((string)($host['PLUGINS_JSON'] ?? ''), true);
        $out = [];
        foreach (is_array($raw) ? $raw : [] as $svc => $cfg) {
            if (!is_string($svc) || !is_array($cfg)) {
                continue;
            }
            $out[$svc] = [
                'enabled' => !empty($cfg['enabled']),
                'max_inflight' => self::clampInflight((int)($cfg['max_inflight'] ?? 0), $svc),
            ];
        }
        return $out;
    }

    public static function serviceEnabled(array $host, string $service): bool
    {
        return !empty(self::plugins($host)[$service]['enabled']);
    }

    public static function maxInflight(array $host, string $service): int
    {
        return self::plugins($host)[$service]['max_inflight'] ?? self::clampInflight(0, $service);
    }

    public static function newSecret(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * @param array<string,array{enabled?:bool,max_inflight?:int}> $plugins
     * @return array{id:int,secret:string}
     */
    public static function create(string $name, string $baseUrl, string $pin, array $plugins = [], string $keyId = 'owner'): array
    {
        $secret = self::newSecret();
        Database::pdo()->prepare(
            'INSERT INTO base_edge_hosts (NAME, TRANSPORT, BASE_URL, KEY_ID, SECRET, PIN_SHA256, ENABLED, PLUGINS_JSON, DT_CREATED)
             VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?)'
        )->execute([
            mb_substr(trim($name), 0, 100),
            self::TRANSPORT_INBOUND,
            rtrim(trim($baseUrl), '/'),
            self::cleanKeyId($keyId),
            $secret,
            trim($pin),
            self::encodePlugins($plugins),
            time(),
        ]);
        return ['id' => (int)Database::pdo()->lastInsertId(), 'secret' => $secret];
    }

    public static function update(int $id, string $name, string $baseUrl, string $pin, bool $enabled, string $keyId): void
    {
        Database::pdo()->prepare(
            'UPDATE base_edge_hosts
                SET NAME = ?, BASE_URL = ?, PIN_SHA256 = ?, ENABLED = ?, KEY_ID = ?, FAILS = 0, BACKOFF_UNTIL = 0
              WHERE ID = ?'
        )->execute([
            mb_substr(trim($name), 0, 100),
            rtrim(trim($baseUrl), '/'),
            trim($pin),
            $enabled ? 1 : 0,
            self::cleanKeyId($keyId),
            $id,
        ]);
    }

    public static function setPlugin(int $id, string $service, bool $enabled, ?int $maxInflight = null): void
    {
        $host = self::find($id);
        if ($host === null) {
            return;
        }
        $plugins = self::plugins($host);
        $plugins[$service] = [
            'enabled' => $enabled,
            'max_inflight' => self::clampInflight($maxInflight ?? (int)($plugins[$service]['max_inflight'] ?? 0), $service),
        ];
        Database::pdo()->prepare('UPDATE base_edge_hosts SET PLUGINS_JSON = ? WHERE ID = ?')
            ->execute([self::encodePlugins($plugins), $id]);
    }

    /** Step 1 of rotation: the host must accept SECRET_NEXT (gateway client secret_next) before promote. */
    public static function rotateNext(int $id): string
    {
        $next = self::newSecret();
        Database::pdo()->prepare('UPDATE base_edge_hosts SET SECRET_NEXT = ? WHERE ID = ?')->execute([$next, $id]);
        return $next;
    }

    public static function promoteNext(int $id): bool
    {
        $st = Database::pdo()->prepare(
            "UPDATE base_edge_hosts SET SECRET = SECRET_NEXT, SECRET_NEXT = '' WHERE ID = ? AND SECRET_NEXT <> ''"
        );
        $st->execute([$id]);
        return $st->rowCount() === 1;
    }

    public static function delete(int $id): void
    {
        EventBus::emit('core.edge_host.deleted', ['id' => $id]);
        Database::pdo()->prepare('DELETE FROM base_edge_hosts WHERE ID = ?')->execute([$id]);
    }

    /**
     * Signed GET /v1/status; remembers the host API version. Does not touch backoff.
     *
     * @return array{ok:bool,detail:string,status:array<string,mixed>}
     */
    public static function probe(array $host): array
    {
        $r = EdgeHostClient::call($host, 'GET', '/v1/status', null, 15);
        if (!$r['ok']) {
            self::noteProbeError((int)$host['ID'], 'test: ' . $r['error']);
            return ['ok' => false, 'detail' => $r['error'], 'status' => []];
        }
        $s = $r['data'];
        self::noteApi((int)$host['ID'], $s);
        $parts = [];
        if (is_array($s['services'] ?? null)) {
            foreach ($s['services'] as $svc => $info) {
                $parts[] = $svc . '=' . (!empty($info['running']) ? 'up' : 'down') . ' v' . (string)($info['version'] ?? '?');
            }
            $detail = sprintf('%s api %d: %s', (string)($s['host'] ?? '?'), (int)($s['api'] ?? 0), implode(', ', $parts) ?: '—');
        } else {
            $detail = sprintf('%s v%s (legacy whisper push)', (string)($s['worker'] ?? '?'), (string)($s['version'] ?? '?'));
        }
        return ['ok' => true, 'detail' => $detail, 'status' => $s];
    }

    /**
     * Status block of one service from a /v1/status response; legacy whisper hosts answer for asr at the top level.
     *
     * @param array<string,mixed> $status
     * @return array<string,mixed>|null null = service missing or not running
     */
    public static function serviceStatus(array $status, string $service): ?array
    {
        if (!is_array($status['services'] ?? null)) {
            return $service === 'asr' ? $status : null;
        }
        $info = $status['services'][$service] ?? null;
        if (!is_array($info) || empty($info['running']) || !is_array($info['status'] ?? null)) {
            return null;
        }
        return $info['status'];
    }

    /** Path of a service endpoint on this host; null when the host cannot serve it. */
    public static function path(array $host, string $service, string $rest): ?string
    {
        if ((int)($host['API_VERSION'] ?? 0) >= 2) {
            return '/v1/' . $service . $rest;
        }
        return $service === 'asr' ? '/v1' . $rest : null;
    }

    /** @param array<string,mixed> $status */
    public static function noteApi(int $id, array $status): void
    {
        $api = is_array($status['services'] ?? null) ? max(2, (int)($status['api'] ?? 2)) : 1;
        Database::pdo()->prepare('UPDATE base_edge_hosts SET API_VERSION = ? WHERE ID = ?')->execute([$api, $id]);
    }

    /** @return array<string,mixed> */
    public static function lastStatus(array $host, string $service): array
    {
        $all = json_decode((string)($host['LAST_STATUS'] ?? ''), true);
        $one = is_array($all) ? ($all[$service] ?? null) : null;
        return is_array($one) ? $one : [];
    }

    /** @param array<string,mixed> $status */
    public static function noteSuccess(int $id, string $service, array $status): void
    {
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare('SELECT LAST_STATUS FROM base_edge_hosts WHERE ID = ? FOR UPDATE');
            $st->execute([$id]);
            $all = json_decode((string)$st->fetchColumn(), true);
            $all = is_array($all) ? $all : [];
            $all[$service] = $status;
            $pdo->prepare(
                "UPDATE base_edge_hosts
                    SET LAST_SEEN = ?, LAST_STATUS = ?, LAST_ERROR = '', FAILS = 0, BACKOFF_UNTIL = 0
                  WHERE ID = ?"
            )->execute([time(), json_encode($all, JSON_UNESCAPED_UNICODE), $id]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Backoff ladder 5m → 10m → 30m → 60m, shared by all services of the host. */
    public static function noteFailure(int $id, string $error): int
    {
        $h = self::find($id);
        $fails = (int)($h['FAILS'] ?? 0) + 1;
        $ladder = [300, 600, 1800, 3600];
        $delay = $ladder[min($fails, count($ladder)) - 1];
        Database::pdo()->prepare(
            'UPDATE base_edge_hosts SET LAST_ERROR = ?, FAILS = ?, BACKOFF_UNTIL = ? WHERE ID = ?'
        )->execute([mb_substr($error, 0, 500), $fails, time() + $delay, $id]);
        return $delay;
    }

    public static function noteProbeError(int $id, string $error): void
    {
        Database::pdo()->prepare('UPDATE base_edge_hosts SET LAST_ERROR = ? WHERE ID = ?')
            ->execute([mb_substr($error, 0, 500), $id]);
    }

    public static function mask(string $secret): string
    {
        return $secret === '' ? '' : substr($secret, 0, 4) . '…' . substr($secret, -4);
    }

    public static function cleanKeyId(string $keyId): string
    {
        $k = preg_replace('~[^A-Za-z0-9._-]~', '', trim($keyId)) ?? '';
        return mb_substr($k !== '' ? $k : 'owner', 0, 64);
    }

    private static function clampInflight(int $n, string $service): int
    {
        $default = self::$services[$service]['max_inflight'] ?? 20;
        return max(1, min(200, $n > 0 ? $n : $default));
    }

    /** @param array<string,array{enabled?:bool,max_inflight?:int}> $plugins */
    private static function encodePlugins(array $plugins): string
    {
        $out = [];
        foreach ($plugins as $svc => $cfg) {
            $out[(string)$svc] = [
                'enabled' => !empty($cfg['enabled']),
                'max_inflight' => self::clampInflight((int)($cfg['max_inflight'] ?? 0), (string)$svc),
            ];
        }
        return (string)json_encode($out, JSON_UNESCAPED_UNICODE);
    }
}
