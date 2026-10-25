<?php
declare(strict_types=1);

final class RecordsDictsRepository
{
    /** Intent / notes field length (independent of llm). */
    public const DESCR_MAX = 200;

    /** @return array<string,string> */
    public static function listSortMap(): array
    {
        $map = ['id' => 'd.ID', 'name' => 'd.NAME', 'disabled' => 'd.DISABLED'];
        if (Database::tableExists('base_records_keys')) {
            $map['keys'] = 'key_count';
        }

        return $map;
    }

    /**
     * @param array{q?:string,order?:string,limit?:int,offset?:int} $opts
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public static function list(array $opts = []): array
    {
        if (!Database::tableExists('base_records_dictonarys')) {
            return ['items' => [], 'total' => 0];
        }
        $pdo = Database::pdo();
        $where = ['1=1'];
        $params = [];
        $q = trim((string)($opts['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(d.NAME LIKE ? OR d.DESCR LIKE ? OR d.TRACKING_CODE LIKE ? OR CAST(d.ID AS CHAR) = ?)';
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $q;
        }
        $sqlWhere = implode(' AND ', $where);
        $stc = $pdo->prepare("SELECT COUNT(*) FROM base_records_dictonarys d WHERE $sqlWhere");
        $stc->execute($params);
        $total = (int)$stc->fetchColumn();
        $order = trim((string)($opts['order'] ?? '')) ?: 'd.ID ASC';
        // Qualify bare columns only (do not touch already-aliased d.ID → d.d.ID).
        $order = preg_replace('/(?<![.\w])ID\b/', 'd.ID', $order) ?? $order;
        $order = preg_replace('/(?<![.\w])NAME\b/', 'd.NAME', $order) ?? $order;
        $order = preg_replace('/(?<![.\w])DISABLED\b/', 'd.DISABLED', $order) ?? $order;
        $limit = max(0, (int)($opts['limit'] ?? 50));
        $offset = max(0, (int)($opts['offset'] ?? 0));
        $keysJoin = '';
        $keysSelect = ', 0 AS key_count';
        if (Database::tableExists('base_records_keys')) {
            $keysJoin = ' LEFT JOIN (
                SELECT ID_DICTONARY AS dict_id, COUNT(*) AS cnt
                FROM base_records_keys
                GROUP BY ID_DICTONARY
             ) kc ON kc.dict_id = d.ID';
            $keysSelect = ', COALESCE(kc.cnt, 0) AS key_count';
        }
        $sql = "SELECT d.ID AS id, d.DT_CREATE AS dt_create, d.NAME AS name, d.DESCR AS descr,
                       d.COLOR AS color, d.COLOR_BACKGROUND AS color_background,
                       d.DISABLED AS disabled, d.IS_REVIEW_REQ AS is_review_req,
                       d.ID_POSITION AS id_position, d.TRACKING_CODE AS tracking_code
                       {$keysSelect}
                FROM base_records_dictonarys d
                {$keysJoin}
                WHERE $sqlWhere
                ORDER BY $order";
        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);

        return ['items' => $st->fetchAll(PDO::FETCH_ASSOC) ?: [], 'total' => $total];
    }

    public static function find(int $id): ?array
    {
        if ($id < 1 || !Database::tableExists('base_records_dictonarys')) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, DT_CREATE AS dt_create, NAME AS name, DESCR AS descr,
                    COLOR AS color, COLOR_BACKGROUND AS color_background,
                    DISABLED AS disabled, IS_REVIEW_REQ AS is_review_req,
                    ID_POSITION AS id_position, TRACKING_CODE AS tracking_code
             FROM base_records_dictonarys WHERE ID = ? LIMIT 1'
        );
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** @return array<int,string> */
    public static function options(bool $includeDisabled = false): array
    {
        $out = [];
        if (!Database::tableExists('base_records_dictonarys')) {
            return $out;
        }
        $sql = 'SELECT ID, NAME FROM base_records_dictonarys';
        if (!$includeDisabled) {
            $sql .= ' WHERE DISABLED < 1';
        }
        $sql .= ' ORDER BY NAME ASC';
        foreach (Database::pdo()->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $out[(int)$r['ID']] = (string)$r['NAME'];
        }
        return $out;
    }

    /**
     * Dict id => position ids from ID_POSITION (empty list = global / all positions).
     *
     * @return array<int,list<int>>
     */
    public static function positionMap(bool $includeDisabled = false): array
    {
        $out = [];
        if (!Database::tableExists('base_records_dictonarys')) {
            return $out;
        }
        $sql = 'SELECT ID, ID_POSITION FROM base_records_dictonarys';
        if (!$includeDisabled) {
            $sql .= ' WHERE DISABLED < 1';
        }
        foreach (Database::pdo()->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $ids = class_exists('RecordsScriptsRepository', false)
                ? RecordsScriptsRepository::parseIds((string)($r['ID_POSITION'] ?? ''))
                : [];
            $out[(int)$r['ID']] = $ids;
        }
        return $out;
    }

    /**
     * Dictionaries allowed for a staff member (status + ID_AUDIO_POSITION peers).
     * Empty ID_POSITION / "0" on a dict = global (all positions).
     *
     * @return array<int,string> id => name
     */
    public static function optionsForAdmin(int $adminId, bool $includeDisabled = false): array
    {
        if ($adminId < 1) {
            return self::options($includeDisabled);
        }
        $allowed = self::adminAllowedPositionIds($adminId);
        if ($allowed === []) {
            return self::options($includeDisabled);
        }
        return self::optionsForPositions($allowed, $includeDisabled);
    }

    /**
     * @param list<int> $positionIds
     * @return array<int,string>
     */
    public static function optionsForPositions(array $positionIds, bool $includeDisabled = false): array
    {
        $out = [];
        if (!Database::tableExists('base_records_dictonarys')) {
            return $out;
        }
        $allowed = [];
        foreach ($positionIds as $pid) {
            $pid = (int)$pid;
            if ($pid > 0) {
                $allowed[$pid] = true;
            }
        }
        $sql = 'SELECT ID, NAME, ID_POSITION FROM base_records_dictonarys';
        if (!$includeDisabled) {
            $sql .= ' WHERE DISABLED < 1';
        }
        $sql .= ' ORDER BY NAME ASC';
        foreach (Database::pdo()->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            if (!self::isPositionAllowed((string)($r['ID_POSITION'] ?? ''), $allowed)) {
                continue;
            }
            $out[(int)$r['ID']] = (string)$r['NAME'];
        }
        return $out;
    }

    /**
     * Status ids that count as the same audio role for this staffer.
     *
     * @return list<int>
     */
    public static function adminAllowedPositionIds(int $adminId): array
    {
        if ($adminId < 1 || !class_exists('UsersRepository', false)) {
            return [];
        }
        $user = UsersRepository::find($adminId);
        if ($user === null) {
            return [];
        }
        $statusId = (int)($user['ID_STATUS'] ?? 0);
        if ($statusId < 1) {
            return [];
        }
        if (class_exists('SummariesRepository', false)) {
            $eq = SummariesRepository::equivalentAudioPositionIds($statusId);
            if ($eq !== []) {
                return $eq;
            }
        }
        return [$statusId];
    }

    /**
     * @param array<int,true>|list<int> $allowedPositions
     */
    public static function isPositionAllowed(string $idPositionRaw, array $allowedPositions): bool
    {
        $raw = trim($idPositionRaw);
        if ($raw === '' || $raw === '0') {
            return true; // global
        }
        $index = [];
        foreach ($allowedPositions as $k => $v) {
            if (is_bool($v) || $v === 1 || $v === '1') {
                $pid = (int)$k;
            } else {
                $pid = (int)$v;
            }
            if ($pid > 0) {
                $index[$pid] = true;
            }
        }
        if ($index === []) {
            return true;
        }
        $ids = class_exists('RecordsScriptsRepository', false)
            ? RecordsScriptsRepository::parseIds($raw)
            : [];
        if ($ids === []) {
            // Fallback parse (#2#,#20# or plain 2)
            foreach (preg_split('/[#,\s]+/', $raw) ?: [] as $p) {
                $n = (int)$p;
                if ($n > 0) {
                    $ids[] = $n;
                }
            }
        }
        if ($ids === []) {
            return true;
        }
        foreach ($ids as $pid) {
            if (isset($index[(int)$pid])) {
                return true;
            }
        }
        return false;
    }

    public static function isDictAllowedForAdmin(int $dictId, int $adminId): bool
    {
        if ($dictId < 1) {
            return false;
        }
        if ($adminId < 1) {
            return true;
        }
        $row = self::find($dictId);
        if ($row === null) {
            return false;
        }
        $allowed = self::adminAllowedPositionIds($adminId);
        if ($allowed === []) {
            return true;
        }
        return self::isPositionAllowed((string)($row['id_position'] ?? ''), $allowed);
    }

    /** @param array<string,mixed> $post @return array<string,mixed> */
    public static function dataFromPost(array $post): array
    {
        $name = trim((string)($post['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException(t('audio_dicts.err.name_required'));
        }
        // Prefer checkbox list (positions[]) like scripts; fall back to raw id_position.
        $idPosition = '';
        if (isset($post['positions']) && is_array($post['positions'])) {
            $ids = array_values(array_filter(array_map('intval', $post['positions'])));
            $idPosition = class_exists('RecordsScriptsRepository', false)
                ? RecordsScriptsRepository::joinIds($ids)
                : '';
        } elseif (array_key_exists('id_position', $post)) {
            $raw = trim((string)$post['id_position']);
            if ($raw !== '' && $raw !== '0' && class_exists('RecordsScriptsRepository', false)) {
                $idPosition = RecordsScriptsRepository::joinIds(
                    RecordsScriptsRepository::parseIds($raw)
                );
            } else {
                $idPosition = ($raw === '0') ? '' : $raw;
            }
        }

        return [
            'name' => mb_substr($name, 0, 50),
            'descr' => mb_substr(trim((string)($post['descr'] ?? '')), 0, self::DESCR_MAX),
            'color' => mb_substr(trim((string)($post['color'] ?? '')), 0, 10),
            'color_background' => mb_substr(trim((string)($post['color_background'] ?? '')), 0, 10),
            'disabled' => !empty($post['disabled']) ? 1 : 0,
            'is_review_req' => !empty($post['is_review_req']) ? 1 : 0,
            'id_position' => $idPosition,
            'tracking_code' => mb_substr(trim((string)($post['tracking_code'] ?? '')), 0, 64),
        ];
    }

    /** @param array<string,mixed> $data */
    public static function create(array $data): int
    {
        $st = Database::pdo()->prepare(
            'INSERT INTO base_records_dictonarys
             (DT_CREATE, NAME, DESCR, COLOR, COLOR_BACKGROUND, DISABLED, IS_REVIEW_REQ, ID_POSITION, TRACKING_CODE)
             VALUES (?,?,?,?,?,?,?,?,?)'
        );
        $st->execute([
            time(), $data['name'], $data['descr'], $data['color'], $data['color_background'],
            $data['disabled'], $data['is_review_req'], $data['id_position'], $data['tracking_code'],
        ]);
        EventBus::emit('audio_dicts.changed', ['entity' => 'dicts']);
        return (int)Database::pdo()->lastInsertId();
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data): void
    {
        $st = Database::pdo()->prepare(
            'UPDATE base_records_dictonarys SET
             NAME=?, DESCR=?, COLOR=?, COLOR_BACKGROUND=?, DISABLED=?, IS_REVIEW_REQ=?, ID_POSITION=?, TRACKING_CODE=?
             WHERE ID=?'
        );
        $st->execute([
            $data['name'], $data['descr'], $data['color'], $data['color_background'],
            $data['disabled'], $data['is_review_req'], $data['id_position'], $data['tracking_code'], $id,
        ]);
        EventBus::emit('audio_dicts.changed', ['entity' => 'dicts', 'id' => $id]);
    }

    public static function updateDescr(int $id, string $descr): void
    {
        if ($id < 1 || !Database::tableExists('base_records_dictonarys')) {
            return;
        }
        $descr = mb_substr(trim($descr), 0, self::DESCR_MAX);
        $st = Database::pdo()->prepare('UPDATE base_records_dictonarys SET DESCR=? WHERE ID=?');
        $st->execute([$descr, $id]);
        EventBus::emit('audio_dicts.changed', ['entity' => 'dicts', 'id' => $id]);
    }

    /** @param list<int> $ids */
    public static function deleteByIds(array $ids): int
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn ($i) => $i > 0));
        if (!$ids) {
            return 0;
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = Database::pdo()->prepare("DELETE FROM base_records_dictonarys WHERE ID IN ($in)");
        $st->execute($ids);
        EventBus::emit('audio_dicts.changed', ['entity' => 'dicts']);
        return $st->rowCount();
    }
}
