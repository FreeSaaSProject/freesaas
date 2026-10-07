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
            $items[] = self::normalizeRow($row, true);
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

    public static function find(int $id, bool $maskCode = true): ?array
    {
        if ($id < 1 || !Database::tableExists('base_terminals')) {
            return null;
        }
        $mass = self::MASS_COL;
        $st = Database::pdo()->prepare(
            "SELECT ID AS id, DT_CREATE AS dt_create, ID_OPERATOR_CREATE AS id_operator_create,
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
             FROM base_terminals WHERE ID = ? LIMIT 1"
        );
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ? self::normalizeRow($row, $maskCode) : null;
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
