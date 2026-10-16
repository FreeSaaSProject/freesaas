<?php
declare(strict_types=1);

/**
 * Device registry (base_terminals).
 * Legacy column IS_MASSЕUR_MODE uses Cyrillic Е — keep alias masseur_mode in PHP.
 */
final class TerminalsRepository
{
    private const MASS_COL = 'IS_MASSЕUR_MODE';

    /** @return array<string,string> */
    public static function listSortMap(): array
    {
        return [
            'id' => 'ID',
            'name' => 'NAME',
            'enabled' => 'ENABLED',
            'dt_create' => 'DT_CREATE',
        ];
    }

    /**
     * @param array{q?:string,enabled?:string,order?:string,limit?:int,offset?:int} $opts
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public static function list(array $opts = []): array
    {
        if (!Database::tableExists('base_terminals')) {
            return ['items' => [], 'total' => 0];
        }
        $pdo = Database::pdo();
        $where = ['1=1'];
        $params = [];
        $q = trim((string)($opts['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(NAME LIKE ? OR CODE LIKE ? OR CAST(ID AS CHAR) = ?)';
            $params[] = '%' . $q . '%';
            $params[] = '%' . $q . '%';
            $params[] = $q;
        }
        $enabled = (string)($opts['enabled'] ?? '');
        if ($enabled === '1' || $enabled === '0') {
            $where[] = 'ENABLED = ?';
            $params[] = (int)$enabled;
        }
        $sqlWhere = implode(' AND ', $where);
        $stc = $pdo->prepare("SELECT COUNT(*) FROM base_terminals WHERE $sqlWhere");
        $stc->execute($params);
        $total = (int)$stc->fetchColumn();

        $order = trim((string)($opts['order'] ?? ''));
        if ($order === '') {
            $order = 'NAME ASC';
        }
        $limit = max(0, (int)($opts['limit'] ?? 50));
        $offset = max(0, (int)($opts['offset'] ?? 0));
        $mass = self::MASS_COL;
        $sql = "SELECT ID AS id, DT_CREATE AS dt_create, ID_OPERATOR_CREATE AS id_operator_create,
                       ENABLED AS enabled, NAME AS name, CODE AS code,
                       ID_ADMIN AS id_admin, DT_ADMIN_VALID AS dt_admin_valid,
                       ID_EXECUTOR AS id_executor, DT_EXECUTOR_FREE AS dt_executor_free,
                       ID_USER_LOCKED AS id_user_locked, IS_ADMIN_REG_IGNORE AS is_admin_reg_ignore,
                       `{$mass}` AS masseur_mode, IS_TERM_ADMIN_MODE AS is_term_admin_mode,
                       EXECUTORS AS executors, GOODS AS goods,
                       DT_EXECUTOR_START AS dt_executor_start, DT_EXECUTOR_END AS dt_executor_end,
                       IS_EMPLOYEE_PROCEDURE_NOTIFY AS is_employee_procedure_notify,
                       IS_BRACELET_NUM_PROMPT AS is_bracelet_num_prompt,
                       IS_TERMINAL_REVIEW_INVITE AS is_terminal_review_invite,
                       IS_TERMINAL_SHOW_DAY AS is_terminal_show_day,
                       IS_TERMINAL_FLYER_DISCOUNT AS is_terminal_flyer_discount,
                       IS_TERMINAL_CLIENT_HISTORY AS is_terminal_client_history
                FROM base_terminals WHERE $sqlWhere ORDER BY $order";
        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $items = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $items[] = self::normalizeRow($row, false);
        }
        return ['items' => $items, 'total' => $total];
    }

    /** @return array<int,string> id => name */
    public static function names(): array
    {
        if (!Database::tableExists('base_terminals')) {
            return [];
        }
        $out = [];
        foreach (Database::pdo()->query(
            'SELECT ID, NAME FROM base_terminals ORDER BY NAME ASC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $out[(int)$r['ID']] = (string)$r['NAME'];
        }
        return $out;
    }

    public static function find(int $id, bool $maskCode = false): ?array
    {
        if ($id < 1 || !Database::tableExists('base_terminals')) {
            return null;
        }
        $st = Database::pdo()->prepare(self::selectSql() . ' WHERE ID = ? LIMIT 1');
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ? self::normalizeRow($row, $maskCode) : null;
    }

    /** Lookup by CODE — for /t auth. */
    public static function findByCode(string $code): ?array
    {
        $code = trim($code);
        if ($code === '' || !Database::tableExists('base_terminals')) {
            return null;
        }
        $st = Database::pdo()->prepare(self::selectSql() . ' WHERE CODE = ? LIMIT 1');
        $st->execute([$code]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ? self::normalizeRow($row, false) : null;
    }

    /**
     * Day claim: set ID_ADMIN + DT_ADMIN_VALID on this terminal; clear same admin elsewhere
     * (unless IS_ADMIN_REG_IGNORE). Valid until min(now+12h, today 23:59:59).
     *
     * @return array{ok:bool,dt_admin_valid?:int,error?:string}
     */
    public static function claimStaffDay(int $terminalId, int $adminId): array
    {
        if ($terminalId < 1 || $adminId < 1 || !Database::tableExists('base_terminals')) {
            return ['ok' => false, 'error' => 'invalid terminal or admin'];
        }
        $term = self::find($terminalId, false);
        if ($term === null || (int)($term['enabled'] ?? 0) < 1) {
            return ['ok' => false, 'error' => 'terminal not found'];
        }
        $now = time();
        $endDay = (int)mktime(23, 59, 59);
        $valid = min($now + 12 * 3600, $endDay);
        $pdo = Database::pdo();
        if ((int)($term['is_admin_reg_ignore'] ?? 0) < 1) {
            $pdo->prepare(
                'UPDATE base_terminals SET ID_ADMIN = 0, DT_ADMIN_VALID = 0
                 WHERE ID <> ? AND ID_ADMIN = ? AND IFNULL(IS_ADMIN_REG_IGNORE, 0) < 1'
            )->execute([$terminalId, $adminId]);
        }
        $pdo->prepare(
            'UPDATE base_terminals SET ID_ADMIN = ?, DT_ADMIN_VALID = ? WHERE ID = ?'
        )->execute([$adminId, $valid, $terminalId]);
        return ['ok' => true, 'dt_admin_valid' => $valid];
    }

    public static function clearStaffClaim(int $terminalId): void
    {
        if ($terminalId < 1 || !Database::tableExists('base_terminals')) {
            return;
        }
        Database::pdo()->prepare(
            'UPDATE base_terminals SET ID_ADMIN = 0, DT_ADMIN_VALID = 0 WHERE ID = ?'
        )->execute([$terminalId]);
    }

    public static function hasValidStaffClaim(array $terminal): bool
    {
        $adminId = (int)($terminal['id_admin'] ?? 0);
        $valid = (int)($terminal['dt_admin_valid'] ?? 0);
        if ((int)($terminal['is_admin_reg_ignore'] ?? 0) > 0) {
            return true;
        }
        return $adminId > 0 && $valid >= time();
    }

    /** Soft lock while choosing/booking on this device (legacy ~50–120s). */
    public static function touchExecutorLock(int $terminalId, int $executorId, int $ttlSec = 50): void
    {
        if ($terminalId < 1 || $executorId < 1 || !Database::tableExists('base_terminals')) {
            return;
        }
        $free = time() + max(10, $ttlSec);
        Database::pdo()->prepare(
            'UPDATE base_terminals SET ID_EXECUTOR = ?, DT_EXECUTOR_FREE = ? WHERE ID = ?'
        )->execute([$executorId, $free, $terminalId]);
    }

    /**
     * Soft-reserve slot on terminal without writing a procedure (legacy api.php bookService).
     * Holds ID_EXECUTOR + planned DT_EXECUTOR_START/END for ~ttlSec.
     */
    public static function reserveExecutorSlot(
        int $terminalId,
        int $executorId,
        int $dtStart,
        int $dtEnd,
        int $ttlSec = 120
    ): bool {
        if ($terminalId < 1 || $executorId < 1 || !Database::tableExists('base_terminals')) {
            return false;
        }
        $free = time() + max(10, $ttlSec);
        $dtStart = max(0, $dtStart);
        $dtEnd = max($dtStart, $dtEnd);
        Database::pdo()->prepare(
            'UPDATE base_terminals SET ID_EXECUTOR = ?, DT_EXECUTOR_FREE = ?,
             DT_EXECUTOR_START = ?, DT_EXECUTOR_END = ? WHERE ID = ?'
        )->execute([$executorId, $free, $dtStart, $dtEnd, $terminalId]);
        return true;
    }

    public static function clearExecutorLock(int $terminalId): void
    {
        if ($terminalId < 1 || !Database::tableExists('base_terminals')) {
            return;
        }
        Database::pdo()->prepare(
            'UPDATE base_terminals SET ID_EXECUTOR = 0, DT_EXECUTOR_FREE = 0,
             DT_EXECUTOR_START = 0, DT_EXECUTOR_END = 0 WHERE ID = ?'
        )->execute([$terminalId]);
    }

    /** @return list<int> admin/executor ids busy on other terminals */
    public static function busyExecutorIds(int $exceptTerminalId = 0): array
    {
        return array_keys(self::busyExecutors($exceptTerminalId));
    }

    /**
     * @return array<int,array{free_time:int,terminal_name:string,terminal_id:int}>
     */
    public static function busyExecutors(int $exceptTerminalId = 0): array
    {
        if (!Database::tableExists('base_terminals')) {
            return [];
        }
        $now = time();
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, NAME AS name, ID_EXECUTOR AS id_executor, DT_EXECUTOR_FREE AS free_time
             FROM base_terminals
             WHERE ID_EXECUTOR > 0 AND DT_EXECUTOR_FREE > ? AND ID <> ?'
        );
        $st->execute([$now, $exceptTerminalId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $eid = (int)($row['id_executor'] ?? 0);
            if ($eid < 1) {
                continue;
            }
            $free = (int)($row['free_time'] ?? 0);
            if (!isset($out[$eid]) || $free > (int)$out[$eid]['free_time']) {
                $out[$eid] = [
                    'free_time' => $free,
                    'terminal_name' => (string)($row['name'] ?? ('#' . (int)$row['id'])),
                    'terminal_id' => (int)($row['id'] ?? 0),
                ];
            }
        }
        return $out;
    }

    private static function selectSql(): string
    {
        $mass = self::MASS_COL;
        return "SELECT ID AS id, DT_CREATE AS dt_create, ID_OPERATOR_CREATE AS id_operator_create,
                    ENABLED AS enabled, NAME AS name, CODE AS code,
                    ID_ADMIN AS id_admin, DT_ADMIN_VALID AS dt_admin_valid,
                    ID_EXECUTOR AS id_executor, DT_EXECUTOR_FREE AS dt_executor_free,
                    ID_USER_LOCKED AS id_user_locked, IS_ADMIN_REG_IGNORE AS is_admin_reg_ignore,
                    `{$mass}` AS masseur_mode, IS_TERM_ADMIN_MODE AS is_term_admin_mode,
                    EXECUTORS AS executors, GOODS AS goods,
                    DT_EXECUTOR_START AS dt_executor_start, DT_EXECUTOR_END AS dt_executor_end,
                    IS_EMPLOYEE_PROCEDURE_NOTIFY AS is_employee_procedure_notify,
                    IS_BRACELET_NUM_PROMPT AS is_bracelet_num_prompt,
                    IS_TERMINAL_REVIEW_INVITE AS is_terminal_review_invite,
                    IS_TERMINAL_SHOW_DAY AS is_terminal_show_day,
                    IS_TERMINAL_FLYER_DISCOUNT AS is_terminal_flyer_discount,
                    IS_TERMINAL_CLIENT_HISTORY AS is_terminal_client_history
             FROM base_terminals";
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function normalizeRow(array $row, bool $maskCode): array
    {
        $code = (string)($row['code'] ?? '');
        $row['code_masked'] = TerminalsConfig::maskCode($code);
        if ($maskCode) {
            $row['code'] = '';
            $row['has_code'] = $code !== '';
        } else {
            $row['has_code'] = $code !== '';
        }
        foreach ([
            'enabled', 'is_admin_reg_ignore', 'masseur_mode', 'is_term_admin_mode',
            'is_employee_procedure_notify', 'is_bracelet_num_prompt', 'is_terminal_review_invite',
            'is_terminal_show_day', 'is_terminal_flyer_discount', 'is_terminal_client_history',
        ] as $flag) {
            $row[$flag] = (int)($row[$flag] ?? 0);
        }
        return $row;
    }

    /**
     * @param array<string,mixed> $post
     * @return array<string,mixed>
     */
    public static function dataFromPost(array $post, bool $isNew): array
    {
        $name = trim((string)($post['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException(t('terminals.err.name_required'));
        }
        $code = trim((string)($post['code'] ?? ''));
        if ($isNew && $code === '') {
            throw new InvalidArgumentException(t('terminals.err.code_required'));
        }
        if ($code !== '' && mb_strlen($code) > 32) {
            throw new InvalidArgumentException(t('terminals.err.code_too_long'));
        }
        return [
            'name' => $name,
            'code' => $code,
            'enabled' => !empty($post['enabled']) ? 1 : 0,
            'is_admin_reg_ignore' => !empty($post['is_admin_reg_ignore']) ? 1 : 0,
            'masseur_mode' => !empty($post['masseur_mode']) ? 1 : 0,
            'is_term_admin_mode' => !empty($post['is_term_admin_mode']) ? 1 : 0,
            'is_employee_procedure_notify' => !empty($post['is_employee_procedure_notify']) ? 1 : 0,
            'is_bracelet_num_prompt' => !empty($post['is_bracelet_num_prompt']) ? 1 : 0,
            'is_terminal_review_invite' => !empty($post['is_terminal_review_invite']) ? 1 : 0,
            'is_terminal_show_day' => !empty($post['is_terminal_show_day']) ? 1 : 0,
            'is_terminal_flyer_discount' => !empty($post['is_terminal_flyer_discount']) ? 1 : 0,
            'is_terminal_client_history' => !empty($post['is_terminal_client_history']) ? 1 : 0,
            'executors' => self::normalizeIdListField((string)($post['executors'] ?? '')),
            'goods' => self::normalizeIdListField((string)($post['goods'] ?? '')),
            'id_user_locked' => max(0, (int)($post['id_user_locked'] ?? 0)),
        ];
    }

    /** @param array<string,mixed> $data */
    public static function create(array $data): int
    {
        $mass = self::MASS_COL;
        $st = Database::pdo()->prepare(
            "INSERT INTO base_terminals (
                DT_CREATE, ID_OPERATOR_CREATE, ENABLED, NAME, CODE,
                IS_ADMIN_REG_IGNORE, `{$mass}`, IS_TERM_ADMIN_MODE,
                EXECUTORS, GOODS, ID_USER_LOCKED,
                IS_EMPLOYEE_PROCEDURE_NOTIFY, IS_BRACELET_NUM_PROMPT,
                IS_TERMINAL_REVIEW_INVITE, IS_TERMINAL_SHOW_DAY,
                IS_TERMINAL_FLYER_DISCOUNT, IS_TERMINAL_CLIENT_HISTORY
             ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
        );
        $st->execute([
            time(),
            (int)(Auth::userId() ?? 0),
            (int)$data['enabled'],
            $data['name'],
            $data['code'],
            (int)$data['is_admin_reg_ignore'],
            (int)$data['masseur_mode'],
            (int)$data['is_term_admin_mode'],
            $data['executors'],
            $data['goods'],
            (int)$data['id_user_locked'],
            (int)$data['is_employee_procedure_notify'],
            (int)$data['is_bracelet_num_prompt'],
            (int)$data['is_terminal_review_invite'],
            (int)$data['is_terminal_show_day'],
            (int)$data['is_terminal_flyer_discount'],
            (int)$data['is_terminal_client_history'],
        ]);
        return (int)Database::pdo()->lastInsertId();
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data): void
    {
        if ($id < 1) {
            throw new InvalidArgumentException('Invalid terminal id');
        }
        $mass = self::MASS_COL;
        $sets = [
            'ENABLED = ?', 'NAME = ?',
            'IS_ADMIN_REG_IGNORE = ?', "`{$mass}` = ?", 'IS_TERM_ADMIN_MODE = ?',
            'EXECUTORS = ?', 'GOODS = ?', 'ID_USER_LOCKED = ?',
            'IS_EMPLOYEE_PROCEDURE_NOTIFY = ?', 'IS_BRACELET_NUM_PROMPT = ?',
            'IS_TERMINAL_REVIEW_INVITE = ?', 'IS_TERMINAL_SHOW_DAY = ?',
            'IS_TERMINAL_FLYER_DISCOUNT = ?', 'IS_TERMINAL_CLIENT_HISTORY = ?',
        ];
        $params = [
            (int)$data['enabled'],
            $data['name'],
            (int)$data['is_admin_reg_ignore'],
            (int)$data['masseur_mode'],
            (int)$data['is_term_admin_mode'],
            $data['executors'],
            $data['goods'],
            (int)$data['id_user_locked'],
            (int)$data['is_employee_procedure_notify'],
            (int)$data['is_bracelet_num_prompt'],
            (int)$data['is_terminal_review_invite'],
            (int)$data['is_terminal_show_day'],
            (int)$data['is_terminal_flyer_discount'],
            (int)$data['is_terminal_client_history'],
        ];
        if (($data['code'] ?? '') !== '') {
            array_unshift($sets, 'CODE = ?');
            array_unshift($params, $data['code']);
        }
        $params[] = $id;
        $st = Database::pdo()->prepare(
            'UPDATE base_terminals SET ' . implode(', ', $sets) . ' WHERE ID = ?'
        );
        $st->execute($params);
    }

    /**
     * @param list<int> $ids
     */
    public static function deleteByIds(array $ids): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static function (int $id): bool {
            return $id > 0;
        })));
        if ($ids === [] || !Database::tableExists('base_terminals')) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $st = Database::pdo()->prepare("DELETE FROM base_terminals WHERE ID IN ($placeholders)");
        $st->execute($ids);
        return $st->rowCount();
    }

    private static function normalizeIdListField(string $raw): string
    {
        $raw = str_replace([',', ';', ' '], '#', $raw);
        $parts = [];
        foreach (explode('#', $raw) as $p) {
            $n = (int)$p;
            if ($n > 0) {
                $parts[$n] = (string)$n;
            }
        }
        return implode('#', array_values($parts));
    }
}
