<?php
declare(strict_types=1);

/**
 * Live share links for KPI report families.
 */
final class BiSharesRepository
{
    /** Max runs per token per rolling minute (guest/staff share). */
    public const RATE_LIMIT_PER_MINUTE = 8;

    public static function tableReady(): bool
    {
        return Database::tableExists('base_bi_shares');
    }

    /**
     * @param array{
     *   family:string,
     *   audience?:string,
     *   ttl?:string,
     *   label?:string,
     *   params?:array<string,mixed>,
     *   staff_user_ids?:list<int>|string
     * } $data
     * @return array<string,mixed>
     */
    public static function create(array $data): array
    {
        if (!self::tableReady()) {
            throw new RuntimeException('shares_table_missing');
        }
        $family = trim((string)($data['family'] ?? ''));
        if ($family === '' || ReportRegistry::get($family) === null) {
            throw new InvalidArgumentException('invalid_family');
        }
        $audience = ReportParams::normalizeAudience((string)($data['audience'] ?? 'guest'));
        $ttlRaw = strtolower(trim((string)($data['ttl'] ?? '1d')));
        if ($ttlRaw === '1y' && $audience !== 'staff') {
            throw new InvalidArgumentException('ttl_1y_staff_only');
        }
        $ttl = ReportParams::normalizeTtl($ttlRaw, $audience);
        $now = time();
        $expire = $now + ReportParams::ttlSeconds($ttl);
        // Sparse constraints only — empty defaults must not lock recipient filters.
        $params = self::sparseParams(is_array($data['params'] ?? null) ? $data['params'] : []);
        $staffIds = self::normalizeStaffIds($data['staff_user_ids'] ?? []);
        // Drop assist-team / unknown ids even if posted manually.
        $allowed = [];
        foreach (BiFilterOptions::shareStaffPicker() as $u) {
            $allowed[(int)$u['id']] = true;
        }
        if ($allowed !== []) {
            $staffIds = array_values(array_filter($staffIds, static function (int $id) use ($allowed): bool {
                return isset($allowed[$id]);
            }));
        }
        $token = self::generateToken();
        $label = mb_substr(trim((string)($data['label'] ?? '')), 0, 255);

        $st = Database::pdo()->prepare(
            'INSERT INTO base_bi_shares
             (TOKEN, FAMILY, AUDIENCE, TTL_KIND, DT_EXPIRE, PARAMS_JSON, STAFF_USER_IDS, LABEL, ID_CREATOR, DT_CREATE, REVOKED_AT, RUN_COUNT, LAST_RUN_AT)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, 0)'
        );
        $st->execute([
            $token,
            $family,
            $audience,
            $ttl,
            $expire,
            json_encode($params, JSON_UNESCAPED_UNICODE),
            json_encode($staffIds, JSON_UNESCAPED_UNICODE),
            $label,
            Auth::userId(),
            $now,
        ]);
        $id = (int)Database::pdo()->lastInsertId();
        $row = self::find($id);
        if ($row === null) {
            throw new RuntimeException('share_create_failed');
        }
        return $row;
    }

    public static function find(int $id): ?array
    {
        if ($id < 1 || !self::tableReady()) {
            return null;
        }
        $st = Database::pdo()->prepare('SELECT * FROM base_bi_shares WHERE ID = ? LIMIT 1');
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ? self::hydrate($row) : null;
    }

    public static function findByToken(string $token): ?array
    {
        $token = trim($token);
        if ($token === '' || !self::tableReady()) {
            return null;
        }
        $st = Database::pdo()->prepare('SELECT * FROM base_bi_shares WHERE TOKEN = ? LIMIT 1');
        $st->execute([$token]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ? self::hydrate($row) : null;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function list(int $limit = 100): array
    {
        if (!self::tableReady()) {
            return [];
        }
        $limit = max(1, min(500, $limit));
        $rows = Database::pdo()->query(
            'SELECT * FROM base_bi_shares ORDER BY ID DESC LIMIT ' . $limit
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $out[] = self::hydrate($row);
        }
        return $out;
    }

    public static function revoke(int $id): bool
    {
        if ($id < 1 || !self::tableReady()) {
            return false;
        }
        $st = Database::pdo()->prepare(
            'UPDATE base_bi_shares SET REVOKED_AT = ? WHERE ID = ? AND REVOKED_AT = 0'
        );
        $st->execute([time(), $id]);
        return $st->rowCount() > 0;
    }

    /**
     * Mark expired (REVOKED_AT = DT_EXPIRE) for cleanup visibility; returns count touched.
     */
    public static function expireDue(): int
    {
        if (!self::tableReady()) {
            return 0;
        }
        $now = time();
        $st = Database::pdo()->prepare(
            'UPDATE base_bi_shares SET REVOKED_AT = DT_EXPIRE
             WHERE REVOKED_AT = 0 AND DT_EXPIRE > 0 AND DT_EXPIRE < ?'
        );
        $st->execute([$now]);
        return $st->rowCount();
    }

    /**
     * @param array<string,mixed> $share hydrated
     */
    public static function isUsable(array $share): bool
    {
        if (empty($share['ID'])) {
            return false;
        }
        if ((int)($share['REVOKED_AT'] ?? 0) > 0) {
            return false;
        }
        $exp = (int)($share['DT_EXPIRE'] ?? 0);
        if ($exp > 0 && $exp < time()) {
            return false;
        }
        return true;
    }

    /**
     * @param array<string,mixed> $share
     */
    public static function audienceAllows(array $share): bool
    {
        $audience = (string)($share['AUDIENCE'] ?? 'guest');
        if ($audience === 'guest') {
            return true;
        }
        if ($audience === 'staff') {
            if (!Auth::check()) {
                return false;
            }
            // Director / first admin can open any staff share (they can also revoke all).
            if (Auth::capability('SUPERADMIN_ACCESS')) {
                return true;
            }
            $ids = is_array($share['staff_user_ids'] ?? null) ? $share['staff_user_ids'] : [];
            if ($ids === []) {
                return true;
            }
            return in_array(Auth::userId(), $ids, true);
        }
        return false;
    }

    /**
     * @param array<string,mixed> $share
     */
    public static function checkRateLimit(array $share): bool
    {
        $id = (int)($share['ID'] ?? 0);
        if ($id < 1) {
            return false;
        }
        $last = (int)($share['LAST_RUN_AT'] ?? 0);
        $count = (int)($share['RUN_COUNT'] ?? 0);
        $now = time();
        // Soft window: if last run within 60s and count high, reject.
        // Persist a minute-bucket via LAST_RUN_AT + RUN_COUNT reset each minute.
        if ($last > 0 && ($now - $last) < 60 && $count >= self::RATE_LIMIT_PER_MINUTE) {
            return false;
        }
        return true;
    }

    /**
     * @param array<string,mixed> $share
     */
    public static function touchRun(array $share): void
    {
        $id = (int)($share['ID'] ?? 0);
        if ($id < 1 || !self::tableReady()) {
            return;
        }
        $now = time();
        $last = (int)($share['LAST_RUN_AT'] ?? 0);
        $count = (int)($share['RUN_COUNT'] ?? 0);
        if ($last > 0 && ($now - $last) < 60) {
            $count++;
        } else {
            $count = 1;
        }
        $st = Database::pdo()->prepare(
            'UPDATE base_bi_shares SET RUN_COUNT = ?, LAST_RUN_AT = ? WHERE ID = ?'
        );
        $st->execute([$count, $now, $id]);
    }

    public static function publicUrl(string $token): string
    {
        $base = rtrim((string)Config::get('app', 'url', ''), '/');
        $path = '/bi/s/' . rawurlencode($token);
        return $base !== '' ? $base . $path : $path;
    }

    /**
     * @param mixed $raw
     * @return list<int>
     */
    public static function normalizeStaffIds($raw): array
    {
        if (is_string($raw)) {
            $raw = preg_split('/[\s,;]+/', $raw) ?: [];
        }
        if (!is_array($raw)) {
            return [];
        }
        $ids = [];
        foreach ($raw as $v) {
            $id = (int)$v;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        return array_values($ids);
    }

    /**
     * Keep only intentionally set constraints (non-zero ids, explicit variant, etc.).
     *
     * @param array<string,mixed> $raw
     * @return array<string,mixed>
     */
    public static function sparseParams(array $raw): array
    {
        $out = [];
        foreach (['id_operator', 'id_admin', 'id_good'] as $k) {
            if (isset($raw[$k]) && (int)$raw[$k] > 0) {
                $out[$k] = (int)$raw[$k];
            }
        }
        if (isset($raw['variant']) && trim((string)$raw['variant']) !== '') {
            $v = strtolower(trim((string)$raw['variant']));
            if (in_array($v, ['ppa', 'full'], true)) {
                $out['variant'] = $v;
            }
        }
        // year/month/dayshow are never locked — share is whole-family live access.
        return $out;
    }

    private static function generateToken(): string
    {
        return bin2hex(random_bytes(24));
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function hydrate(array $row): array
    {
        $params = json_decode((string)($row['PARAMS_JSON'] ?? ''), true);
        if (!is_array($params)) {
            $params = [];
        }
        $staff = json_decode((string)($row['STAFF_USER_IDS'] ?? ''), true);
        if (!is_array($staff)) {
            $staff = [];
        }
        $token = (string)($row['TOKEN'] ?? '');
        $hydrated = [
            'ID' => (int)($row['ID'] ?? 0),
            'TOKEN' => $token,
            'FAMILY' => (string)($row['FAMILY'] ?? ''),
            'AUDIENCE' => (string)($row['AUDIENCE'] ?? 'guest'),
            'TTL_KIND' => (string)($row['TTL_KIND'] ?? '1d'),
            'DT_EXPIRE' => (int)($row['DT_EXPIRE'] ?? 0),
            'params' => self::sparseParams($params),
            'staff_user_ids' => self::normalizeStaffIds($staff),
            'LABEL' => (string)($row['LABEL'] ?? ''),
            'ID_CREATOR' => (int)($row['ID_CREATOR'] ?? 0),
            'DT_CREATE' => (int)($row['DT_CREATE'] ?? 0),
            'REVOKED_AT' => (int)($row['REVOKED_AT'] ?? 0),
            'RUN_COUNT' => (int)($row['RUN_COUNT'] ?? 0),
            'LAST_RUN_AT' => (int)($row['LAST_RUN_AT'] ?? 0),
            'url' => $token !== '' ? self::publicUrl($token) : '',
        ];
        $hydrated['usable'] = self::isUsable($hydrated);
        return $hydrated;
    }
}

