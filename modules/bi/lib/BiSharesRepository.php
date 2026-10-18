<?php
declare(strict_types=1);

/**
 * Live share links for KPI report families.
 */
final class BiSharesRepository
{
    /** Max runs per token per rolling minute (guest/staff share). */
    public const RATE_LIMIT_PER_MINUTE = 8;

    /** One in-flight recompute per share (GET_LOCK). */
    public const RUN_LOCK_PREFIX = 'bi_share_run_';

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
     *   staff_user_ids?:list<int>|string,
     *   staff_status_ids?:list<int>|string
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
        $coverage = self::filterCoverage($family, $params);
        $acceptPartial = !empty($data['accept_partial_filters']);
        if (!$coverage['ok'] && !$acceptPartial) {
            throw new InvalidArgumentException('filters_partial');
        }

        // Detect non-empty input *before* normalize — [0]/[-1]/["invalid"] must not become "all staff".
        $rawStaffInput = $data['staff_user_ids'] ?? null;
        $hadExplicitStaff = self::staffInputProvided($rawStaffInput);
        $staffIds = self::normalizeStaffIds($rawStaffInput ?? []);
        // Drop hidden / unknown ids even if posted manually.
        $allowed = [];
        foreach (BiFilterOptions::shareStaffPicker() as $u) {
            $allowed[(int)$u['id']] = true;
        }
        if ($allowed !== []) {
            $staffIds = array_values(array_filter($staffIds, static function (int $id) use ($allowed): bool {
                return isset($allowed[$id]);
            }));
        }
        // Explicit allowlist that empties after normalize/allowlist must not become "all staff".
        if ($hadExplicitStaff && $staffIds === []) {
            throw new InvalidArgumentException('staff_allowlist_empty');
        }

        $rawStatusInput = $data['staff_status_ids'] ?? null;
        $hadExplicitStatus = self::staffInputProvided($rawStatusInput);
        $statusIds = self::normalizeStaffIds($rawStatusInput ?? []);
        $allowedStatuses = [];
        foreach (BiFilterOptions::shareStatusPicker() as $s) {
            $allowedStatuses[(int)$s['id']] = true;
        }
        if ($allowedStatuses !== []) {
            $statusIds = array_values(array_filter($statusIds, static function (int $id) use ($allowedStatuses): bool {
                return isset($allowedStatuses[$id]);
            }));
        }
        if ($hadExplicitStatus && $statusIds === []) {
            throw new InvalidArgumentException('status_allowlist_empty');
        }

        $token = self::generateToken();
        $label = mb_substr(trim((string)($data['label'] ?? '')), 0, 255);
        $hasStatusCol = self::hasStatusIdsColumn();

        if ($hasStatusCol) {
            $st = Database::pdo()->prepare(
                'INSERT INTO base_bi_shares
                 (TOKEN, FAMILY, AUDIENCE, TTL_KIND, DT_EXPIRE, PARAMS_JSON, STAFF_USER_IDS, STAFF_STATUS_IDS, LABEL, ID_CREATOR, DT_CREATE, REVOKED_AT, RUN_COUNT, LAST_RUN_AT)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, 0)'
            );
            $st->execute([
                $token,
                $family,
                $audience,
                $ttl,
                $expire,
                json_encode($params, JSON_UNESCAPED_UNICODE),
                json_encode($staffIds, JSON_UNESCAPED_UNICODE),
                json_encode($statusIds, JSON_UNESCAPED_UNICODE),
                $label,
                Auth::userId(),
                $now,
            ]);
        } else {
            if ($statusIds !== []) {
                throw new RuntimeException('shares_status_column_missing');
            }
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
        }
        $id = (int)Database::pdo()->lastInsertId();
        $row = self::find($id);
        if ($row === null) {
            throw new RuntimeException('share_create_failed');
        }
        $row['filter_coverage'] = $coverage;
        return $row;
    }

    public static function hasStatusIdsColumn(): bool
    {
        return self::tableReady() && Database::columnExists('base_bi_shares', 'STAFF_STATUS_IDS');
    }

    /**
     * Which locked share params actually bind on each recipe query dataset.
     *
     * @param array<string,mixed> $constraints sparse params (id_operator / id_admin / id_good)
     * @return array{ok:bool,locked:list<string>,uncovered:list<array{query:string,dataset:string,param:string}>}
     */
    public static function filterCoverage(string $familyId, array $constraints): array
    {
        $map = [
            'id_operator' => 'operator',
            'id_admin' => 'admin',
            'id_good' => 'good',
        ];
        $locked = [];
        foreach ($map as $param => $filterKey) {
            if ((int)($constraints[$param] ?? 0) > 0) {
                $locked[$param] = $filterKey;
            }
        }
        if ($locked === []) {
            return ['ok' => true, 'locked' => [], 'uncovered' => []];
        }

        $family = ReportRegistry::get($familyId);
        $recipe = [];
        if ($family instanceof RecipeFamily) {
            $recipe = $family->recipe();
        } elseif (class_exists('BiShippedRecipes', false)) {
            $recipe = BiShippedRecipes::get($familyId) ?? [];
        }

        $uncovered = [];
        $queries = is_array($recipe['queries'] ?? null) ? $recipe['queries'] : [];
        foreach ($queries as $q) {
            if (!is_array($q)) {
                continue;
            }
            $as = (string)($q['as'] ?? '');
            $from = (string)($q['from'] ?? '');
            $ds = BiDatasetCatalog::get($from);
            $filters = is_array($ds['filters'] ?? null) ? $ds['filters'] : [];
            foreach ($locked as $param => $filterKey) {
                if (!isset($filters[$filterKey])) {
                    $uncovered[] = [
                        'query' => $as !== '' ? $as : $from,
                        'dataset' => $from,
                        'param' => $param,
                    ];
                }
            }
        }

        // Pivot metrics also issue per-dataset queries.
        $sections = is_array($recipe['sections'] ?? null) ? $recipe['sections'] : [];
        foreach ($sections as $si => $sec) {
            if (!is_array($sec) || (string)($sec['type'] ?? '') !== 'pivot') {
                continue;
            }
            $metrics = is_array($sec['metrics'] ?? null) ? $sec['metrics'] : [];
            foreach ($metrics as $mi => $m) {
                if (!is_array($m)) {
                    continue;
                }
                $from = (string)($m['from'] ?? '');
                $ds = BiDatasetCatalog::get($from);
                $filters = is_array($ds['filters'] ?? null) ? $ds['filters'] : [];
                foreach ($locked as $param => $filterKey) {
                    if (!isset($filters[$filterKey])) {
                        $uncovered[] = [
                            'query' => 'pivot[' . $si . '].metrics[' . $mi . ']',
                            'dataset' => $from,
                            'param' => $param,
                        ];
                    }
                }
            }
        }

        return [
            'ok' => $uncovered === [],
            'locked' => array_keys($locked),
            'uncovered' => $uncovered,
        ];
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

    /**
     * Active staff-audience shares the current logged-in user may open.
     *
     * @return list<array<string,mixed>>
     */
    public static function listUsableForCurrentStaff(int $limit = 200): array
    {
        if (!Auth::check() || !self::tableReady()) {
            return [];
        }
        $limit = max(1, min(500, $limit));
        $now = time();
        $st = Database::pdo()->prepare(
            'SELECT * FROM base_bi_shares
             WHERE AUDIENCE = ?
               AND COALESCE(REVOKED_AT, 0) = 0
               AND (DT_EXPIRE = 0 OR DT_EXPIRE >= ?)
             ORDER BY ID DESC
             LIMIT ' . $limit
        );
        $st->execute(['staff', $now]);
        $out = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $share = self::hydrate($row);
            if (!self::isUsable($share) || !self::audienceAllows($share)) {
                continue;
            }
            $out[] = $share;
        }
        return $out;
    }

    /**
     * Newest usable staff share for a family that the current user may open.
     *
     * @return array<string,mixed>|null
     */
    public static function findUsableStaffShareForFamily(string $familyId): ?array
    {
        $familyId = trim($familyId);
        if ($familyId === '') {
            return null;
        }
        foreach (self::listUsableForCurrentStaff() as $share) {
            if ((string)($share['FAMILY'] ?? '') === $familyId) {
                return $share;
            }
        }
        return null;
    }

    /**
     * Resolve a staff share for in-app /bi access (token preferred, else family lookup).
     *
     * @return array<string,mixed>|null
     */
    public static function resolveStaffShareAccess(string $familyId, string $token = ''): ?array
    {
        $token = trim($token);
        if ($token !== '') {
            $share = self::findByToken($token);
            if (
                $share !== null
                && self::isUsable($share)
                && (string)($share['AUDIENCE'] ?? '') === 'staff'
                && self::audienceAllows($share)
                && (string)($share['FAMILY'] ?? '') === trim($familyId)
            ) {
                return $share;
            }
            return null;
        }
        return self::findUsableStaffShareForFamily($familyId);
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
            $statusIds = is_array($share['staff_status_ids'] ?? null) ? $share['staff_status_ids'] : [];
            // Both empty = any logged-in staff.
            if ($ids === [] && $statusIds === []) {
                return true;
            }
            // Union: person allowlist OR role allowlist.
            if ($ids !== [] && in_array(Auth::userId(), $ids, true)) {
                return true;
            }
            if ($statusIds !== []) {
                $mine = [Auth::statusId(), Auth::statusId2()];
                foreach ($mine as $sid) {
                    if ($sid > 0 && in_array($sid, $statusIds, true)) {
                        return true;
                    }
                }
            }
            return false;
        }
        return false;
    }

    /**
     * Soft check (non-atomic). Prefer beginRun() for public recompute.
     *
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
        if ($last > 0 && ($now - $last) < 60 && $count >= self::RATE_LIMIT_PER_MINUTE) {
            return false;
        }
        return true;
    }

    /**
     * Acquire per-share lock + atomically reserve a rate-limit slot.
     * Caller must endRun() in finally.
     *
     * @param array<string,mixed> $share
     * @return array{id:int,lock:string}|null null = rate-limited or another run in flight
     */
    public static function beginRun(array $share): ?array
    {
        $id = (int)($share['ID'] ?? 0);
        if ($id < 1 || !self::tableReady()) {
            return null;
        }
        $pdo = Database::pdo();
        $lock = self::RUN_LOCK_PREFIX . $id;
        $st = $pdo->prepare('SELECT GET_LOCK(?, 0)');
        $st->execute([$lock]);
        if ((int)$st->fetchColumn() !== 1) {
            return null;
        }
        if (!self::reserveRateSlot($id)) {
            $rel = $pdo->prepare('SELECT RELEASE_LOCK(?)');
            $rel->execute([$lock]);
            return null;
        }
        return ['id' => $id, 'lock' => $lock];
    }

    /**
     * @param array{id:int,lock:string}|null $ticket
     */
    public static function endRun(?array $ticket): void
    {
        if ($ticket === null || !self::tableReady()) {
            return;
        }
        $lock = (string)($ticket['lock'] ?? '');
        if ($lock === '') {
            return;
        }
        try {
            $st = Database::pdo()->prepare('SELECT RELEASE_LOCK(?)');
            $st->execute([$lock]);
        } catch (Throwable $e) {
            // ignore unlock errors
        }
    }

    /**
     * Atomic minute-bucket increment. Returns false when limit exceeded / row gone.
     */
    public static function reserveRateSlot(int $id): bool
    {
        if ($id < 1 || !self::tableReady()) {
            return false;
        }
        $limit = self::RATE_LIMIT_PER_MINUTE;
        $st = Database::pdo()->prepare(
            'UPDATE base_bi_shares
             SET RUN_COUNT = IF(LAST_RUN_AT > 0 AND (UNIX_TIMESTAMP() - LAST_RUN_AT) < 60, RUN_COUNT + 1, 1),
                 LAST_RUN_AT = UNIX_TIMESTAMP()
             WHERE ID = ?
               AND COALESCE(REVOKED_AT, 0) = 0
               AND (DT_EXPIRE = 0 OR DT_EXPIRE >= UNIX_TIMESTAMP())
               AND (
                 LAST_RUN_AT = 0
                 OR (UNIX_TIMESTAMP() - LAST_RUN_AT) >= 60
                 OR RUN_COUNT < ?
               )'
        );
        $st->execute([$id, $limit]);
        return $st->rowCount() > 0;
    }

    /**
     * @deprecated use beginRun/endRun
     * @param array<string,mixed> $share
     */
    public static function touchRun(array $share): void
    {
        self::reserveRateSlot((int)($share['ID'] ?? 0));
    }

    public static function publicUrl(string $token): string
    {
        $base = rtrim((string)Config::get('app', 'url', ''), '/');
        $path = '/bi/s/' . rawurlencode($token);
        return $base !== '' ? $base . $path : $path;
    }

    /**
     * True when caller supplied a non-blank staff list (even if every token is invalid).
     * Missing / null / [] / blank string = intentionally open to all staff.
     *
     * @param mixed $raw
     */
    public static function staffInputProvided($raw): bool
    {
        if ($raw === null) {
            return false;
        }
        if (is_string($raw)) {
            if (trim($raw) === '') {
                return false;
            }
            $raw = preg_split('/[\s,;]+/', $raw) ?: [];
        }
        if (!is_array($raw)) {
            // Scalar attempt: 0, -1, "invalid", false→skip, true→attempt
            if ($raw === false || $raw === '') {
                return false;
            }
            return true;
        }
        foreach ($raw as $v) {
            if ($v === null || $v === false || $v === '') {
                continue;
            }
            if (is_string($v) && trim($v) === '') {
                continue;
            }
            return true;
        }
        return false;
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
            if (is_int($raw) || is_float($raw) || (is_string($raw) && is_numeric($raw))) {
                $id = (int)$raw;
                return $id > 0 ? [$id] : [];
            }
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
        $statuses = json_decode((string)($row['STAFF_STATUS_IDS'] ?? ''), true);
        if (!is_array($statuses)) {
            $statuses = [];
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
            'staff_status_ids' => self::normalizeStaffIds($statuses),
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

