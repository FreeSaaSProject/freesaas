<?php
declare(strict_types=1);

/** base_client_mcp_sessions: anonymous agent sessions keyed by a hashed client_token. */
final class ClientMcpSessionsRepository
{
    public const TOKEN_PREFIX = 'cmt_';

    public static function tableReady(): bool
    {
        return Database::tableExists('base_client_mcp_sessions');
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * @param array{site_host_id:int,phone:string,client_id:int,client_name:string,known:bool,verify_mode:string,consent:bool,ip:string,user_agent:string} $data
     * @return array{token:string,expires_at:int,id:int}
     */
    public static function create(array $data, int $ttlSeconds): array
    {
        $token = self::TOKEN_PREFIX . bin2hex(random_bytes(24));
        $now = time();
        $expires = $now + max(60, $ttlSeconds);
        $verifyMode = (string)$data['verify_mode'];
        Database::pdo()->prepare(
            'INSERT INTO base_client_mcp_sessions
                (TOKEN_HASH, SITE_HOST_ID, PHONE, ID_CLIENT, CLIENT_NAME, KNOWN_CLIENT, VERIFY_MODE, VERIFIED, CONSENT_AT,
                 ORDER_IDS, BOOKINGS, IP, USER_AGENT, DT_CREATE, DT_SEEN, DT_EXPIRE)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?)'
        )->execute([
            self::hashToken($token),
            max(0, (int)$data['site_host_id']),
            mb_substr((string)$data['phone'], 0, 20),
            max(0, (int)$data['client_id']),
            mb_substr((string)$data['client_name'], 0, 255),
            !empty($data['known']) ? 1 : 0,
            mb_substr($verifyMode !== '' ? $verifyMode : 'none', 0, 16),
            0,
            !empty($data['consent']) ? $now : 0,
            '[]',
            mb_substr((string)$data['ip'], 0, 45),
            mb_substr((string)$data['user_agent'], 0, 255),
            $now,
            $now,
            $expires,
        ]);
        return [
            'token' => $token,
            'expires_at' => $expires,
            'id' => (int)Database::pdo()->lastInsertId(),
        ];
    }

    /** @return array<string,mixed>|null live (not expired) session issued by this storefront host */
    public static function findByToken(string $token, int $siteHostId): ?array
    {
        $token = trim($token);
        if ($token === '' || strpos($token, self::TOKEN_PREFIX) !== 0 || !self::tableReady()) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT * FROM base_client_mcp_sessions WHERE TOKEN_HASH = ? AND SITE_HOST_ID = ? AND DT_EXPIRE > ? LIMIT 1'
        );
        $st->execute([self::hashToken($token), max(0, $siteHostId), time()]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $row = self::normalize($row);
        Database::pdo()->prepare('UPDATE base_client_mcp_sessions SET DT_SEEN = ? WHERE ID = ?')
            ->execute([time(), (int)$row['id']]);
        return $row;
    }

    /** @param array<string,mixed> $fields subset: client_id, client_name, verified, consent */
    public static function update(int $id, array $fields): void
    {
        $sets = [];
        $vals = [];
        if (array_key_exists('client_id', $fields)) {
            $sets[] = 'ID_CLIENT = ?';
            $vals[] = max(0, (int)$fields['client_id']);
        }
        if (array_key_exists('client_name', $fields)) {
            $sets[] = 'CLIENT_NAME = ?';
            $vals[] = mb_substr((string)$fields['client_name'], 0, 255);
        }
        if (array_key_exists('verified', $fields)) {
            $sets[] = 'VERIFIED = ?';
            $vals[] = !empty($fields['verified']) ? 1 : 0;
        }
        if (!empty($fields['consent'])) {
            $sets[] = 'CONSENT_AT = IF(CONSENT_AT = 0, ?, CONSENT_AT)';
            $vals[] = time();
        }
        if ($sets === [] || $id < 1) {
            return;
        }
        $vals[] = $id;
        Database::pdo()->prepare('UPDATE base_client_mcp_sessions SET ' . implode(', ', $sets) . ' WHERE ID = ?')
            ->execute($vals);
    }

    public static function addOrder(int $id, int $orderId, int $clientId): void
    {
        if ($id < 1 || $orderId < 1) {
            return;
        }
        $pdo = Database::pdo();
        $st = $pdo->prepare('SELECT ORDER_IDS FROM base_client_mcp_sessions WHERE ID = ? LIMIT 1');
        $st->execute([$id]);
        $ids = self::decodeIds((string)($st->fetchColumn() ?: '[]'));
        if (!in_array($orderId, $ids, true)) {
            $ids[] = $orderId;
        }
        $pdo->prepare(
            'UPDATE base_client_mcp_sessions
             SET ORDER_IDS = ?, BOOKINGS = BOOKINGS + 1, ID_CLIENT = IF(ID_CLIENT = 0, ?, ID_CLIENT)
             WHERE ID = ?'
        )->execute([json_encode($ids), max(0, $clientId), $id]);
    }

    public static function countByIpSince(string $ip, int $since): int
    {
        if ($ip === '' || !self::tableReady()) {
            return 0;
        }
        $st = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM base_client_mcp_sessions WHERE IP = ? AND DT_CREATE >= ?'
        );
        $st->execute([$ip, $since]);
        return (int)$st->fetchColumn();
    }

    public static function bookingsByPhoneSince(string $phone, int $since): int
    {
        if ($phone === '' || !self::tableReady()) {
            return 0;
        }
        $st = Database::pdo()->prepare(
            'SELECT COALESCE(SUM(BOOKINGS), 0) FROM base_client_mcp_sessions WHERE PHONE = ? AND DT_CREATE >= ?'
        );
        $st->execute([$phone, $since]);
        return (int)$st->fetchColumn();
    }

    public static function purgeExpired(): int
    {
        if (!self::tableReady()) {
            return 0;
        }
        // Keep a day after expiry so per-phone daily limits still see recent bookings.
        $st = Database::pdo()->prepare('DELETE FROM base_client_mcp_sessions WHERE DT_EXPIRE < ?');
        $st->execute([time() - 86400]);
        return $st->rowCount();
    }

    /** @return array{active:int,last_day:int,bookings_last_day:int} */
    public static function stats(int $siteHostId): array
    {
        if (!self::tableReady()) {
            return ['active' => 0, 'last_day' => 0, 'bookings_last_day' => 0];
        }
        $now = time();
        $st = Database::pdo()->prepare(
            'SELECT SUM(DT_EXPIRE > ?) AS active,
                    SUM(DT_CREATE >= ?) AS last_day,
                    COALESCE(SUM(IF(DT_CREATE >= ?, BOOKINGS, 0)), 0) AS bookings_last_day
             FROM base_client_mcp_sessions WHERE SITE_HOST_ID = ?'
        );
        $st->execute([$now, $now - 86400, $now - 86400, max(0, $siteHostId)]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'active' => (int)($row['active'] ?? 0),
            'last_day' => (int)($row['last_day'] ?? 0),
            'bookings_last_day' => (int)($row['bookings_last_day'] ?? 0),
        ];
    }

    /** @return list<int> */
    private static function decodeIds(string $raw): array
    {
        $arr = json_decode($raw, true);
        if (!is_array($arr)) {
            return [];
        }
        $out = [];
        foreach ($arr as $v) {
            $v = (int)$v;
            if ($v > 0) {
                $out[] = $v;
            }
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function normalize(array $row): array
    {
        return [
            'id' => (int)$row['ID'],
            'site_host_id' => (int)($row['SITE_HOST_ID'] ?? 0),
            'phone' => (string)$row['PHONE'],
            'client_id' => (int)$row['ID_CLIENT'],
            'client_name' => (string)$row['CLIENT_NAME'],
            'known' => (int)$row['KNOWN_CLIENT'] === 1,
            'verify_mode' => (string)$row['VERIFY_MODE'],
            'verified' => (int)$row['VERIFIED'] === 1,
            'consent_at' => (int)$row['CONSENT_AT'],
            'order_ids' => self::decodeIds((string)$row['ORDER_IDS']),
            'bookings' => (int)$row['BOOKINGS'],
            'dt_create' => (int)$row['DT_CREATE'],
            'dt_expire' => (int)$row['DT_EXPIRE'],
        ];
    }
}
