<?php
declare(strict_types=1);

final class AccountMoveTypesRepository
{
    /** @return array<string,string> */
    public static function listSortMap(): array
    {
        return [
            'id' => 'ID',
            'name' => 'NAME',
            'pid' => 'PID',
            'num' => 'NUM',
            'enabled' => 'ENABLED',
        ];
    }

    /**
     * @param array{q?:string,order?:string,limit?:int,offset?:int} $opts
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public static function list(array $opts = []): array
    {
        if (!Database::tableExists('base_account_move_types')) {
            return ['items' => [], 'total' => 0];
        }
        $pdo = Database::pdo();
        $where = ['1=1'];
        $params = [];
        $q = trim((string)($opts['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(NAME LIKE ? OR DESCR LIKE ? OR CAST(ID AS CHAR) = ?)';
            $params[] = '%' . $q . '%';
            $params[] = '%' . $q . '%';
            $params[] = $q;
        }
        $sqlWhere = implode(' AND ', $where);
        $stc = $pdo->prepare("SELECT COUNT(*) FROM base_account_move_types WHERE $sqlWhere");
        $stc->execute($params);
        $total = (int)$stc->fetchColumn();
        $order = trim((string)($opts['order'] ?? '')) ?: 'PID ASC, NUM ASC, NAME ASC';
        $limit = max(0, (int)($opts['limit'] ?? 100));
        $offset = max(0, (int)($opts['offset'] ?? 0));
        $sql = 'SELECT ID AS id, NAME AS name, DESCR AS descr, DT AS dt, PID AS pid,
                       ENABLED AS enabled, ID_ACCOUNT AS account_id, NUM AS num,
                       DIRECTION AS direction, ID_TYPE AS odds_type,
                       ID_PAYMENT_ENABLED AS payment_enabled,
                       ID_USER_PAYMENT_ENABLED AS user_payment_enabled,
                       ID_SUPPLIER_ENABLED AS supplier_enabled,
                       ID_MOVEMENT_LINKED_ENABLED AS linked_enabled,
                       ID_FUND_ENABLED AS fund_enabled,
                       EXCLUDE_PL AS exclude_pl
                FROM base_account_move_types WHERE ' . $sqlWhere . ' ORDER BY ' . $order;
        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return ['items' => $st->fetchAll(PDO::FETCH_ASSOC) ?: [], 'total' => $total];
    }

    /** @return array<int,string> */
    public static function names(bool $enabledOnly = false): array
    {
        if (!Database::tableExists('base_account_move_types')) {
            return [];
        }
        $sql = 'SELECT ID, NAME, PID FROM base_account_move_types';
        if ($enabledOnly) {
            $sql .= ' WHERE ENABLED > 0';
        }
        $sql .= ' ORDER BY PID ASC, NUM ASC, NAME ASC';
        $out = [];
        foreach (Database::pdo()->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $prefix = ((int)$r['PID'] > 0) ? '— ' : '';
            $out[(int)$r['ID']] = $prefix . (string)$r['NAME'];
        }
        return $out;
    }

    /** Leaf types suitable for posting (enabled, typically with direction). */
    /** @return array<int,string> */
    public static function leafNames(): array
    {
        if (!Database::tableExists('base_account_move_types')) {
            return [];
        }
        $out = [];
        $rows = Database::pdo()->query(
            'SELECT ID, NAME FROM base_account_move_types WHERE ENABLED > 0 ORDER BY PID ASC, NUM ASC, NAME ASC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $r) {
            $out[(int)$r['ID']] = (string)$r['NAME'];
        }
        return $out;
    }

    public static function find(int $id): ?array
    {
        if ($id < 1 || !Database::tableExists('base_account_move_types')) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, NAME AS name, DESCR AS descr, DT AS dt, PID AS pid,
                    ENABLED AS enabled, ID_ACCOUNT AS account_id, NUM AS num,
                    DIRECTION AS direction, ID_TYPE AS odds_type,
                    ID_ACT_IN_ENABLED AS act_in_enabled,
                    ID_PAYMENT_ENABLED AS payment_enabled,
                    ID_USER_PAYMENT_ENABLED AS user_payment_enabled,
                    ID_SUPPLIER_ENABLED AS supplier_enabled,
                    ID_INCASS_ENABLED AS incass_enabled,
                    ID_MOVEMENT_LINKED_ENABLED AS linked_enabled,
                    ID_FUND_ENABLED AS fund_enabled,
                    IS_VARIABLE AS is_variable, EXCLUDE_PL AS exclude_pl,
                    IS_USNO_DISCOUNTING AS usno_discounting, IS_USNO_PAYING AS usno_paying,
                    IS_DIV AS is_div
             FROM base_account_move_types WHERE ID = ? LIMIT 1'
        );
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function findByName(string $name): ?array
    {
        $name = trim($name);
        if ($name === '' || !Database::tableExists('base_account_move_types')) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, NAME AS name, ID_ACCOUNT AS account_id, DIRECTION AS direction,
                    ID_PAYMENT_ENABLED AS payment_enabled
             FROM base_account_move_types WHERE NAME = ? AND ENABLED > 0 LIMIT 1'
        );
        $st->execute([$name]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** @param array<string,mixed> $post @return array<string,mixed> */
    public static function dataFromPost(array $post): array
    {
        $name = trim((string)($post['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException(t('ledger.types.err.name'));
        }
        $direction = (int)($post['direction'] ?? 0);
        if (!in_array($direction, [-1, 0, 1], true)) {
            $direction = 0;
        }
        $odds = (int)($post['odds_type'] ?? 1);
        if (!in_array($odds, [1, 2, 3], true)) {
            $odds = 1;
        }
        return [
            'name' => $name,
            'descr' => trim((string)($post['descr'] ?? '')),
            'pid' => max(0, (int)($post['pid'] ?? 0)),
            'enabled' => !empty($post['enabled']) ? 1 : 0,
            'account_id' => max(0, (int)($post['account_id'] ?? 0)),
            'num' => (int)($post['num'] ?? 0),
            'direction' => $direction,
            'odds_type' => $odds,
            'payment_enabled' => !empty($post['payment_enabled']) ? 1 : 0,
            'user_payment_enabled' => !empty($post['user_payment_enabled']) ? 1 : 0,
            'supplier_enabled' => !empty($post['supplier_enabled']) ? 1 : 0,
            'linked_enabled' => !empty($post['linked_enabled']) ? 1 : 0,
            'fund_enabled' => !empty($post['fund_enabled']) ? 1 : 0,
            'exclude_pl' => !empty($post['exclude_pl']) ? 1 : 0,
            'dt' => time(),
        ];
    }

    /** @param array<string,mixed> $data */
    public static function create(array $data): int
    {
        $st = Database::pdo()->prepare(
            'INSERT INTO base_account_move_types
             (NAME, DESCR, DT, PID, ENABLED, ID_ACCOUNT, NUM, DIRECTION, ID_TYPE,
              ID_PAYMENT_ENABLED, ID_USER_PAYMENT_ENABLED, ID_SUPPLIER_ENABLED,
              ID_MOVEMENT_LINKED_ENABLED, ID_FUND_ENABLED, EXCLUDE_PL)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $st->execute([
            $data['name'], $data['descr'], $data['dt'], $data['pid'], $data['enabled'],
            $data['account_id'], $data['num'], $data['direction'], $data['odds_type'],
            $data['payment_enabled'], $data['user_payment_enabled'], $data['supplier_enabled'],
            $data['linked_enabled'], $data['fund_enabled'], $data['exclude_pl'],
        ]);
        return (int)Database::pdo()->lastInsertId();
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data): void
    {
        $st = Database::pdo()->prepare(
            'UPDATE base_account_move_types SET
             NAME = ?, DESCR = ?, PID = ?, ENABLED = ?, ID_ACCOUNT = ?, NUM = ?,
             DIRECTION = ?, ID_TYPE = ?,
             ID_PAYMENT_ENABLED = ?, ID_USER_PAYMENT_ENABLED = ?, ID_SUPPLIER_ENABLED = ?,
             ID_MOVEMENT_LINKED_ENABLED = ?, ID_FUND_ENABLED = ?, EXCLUDE_PL = ?
             WHERE ID = ?'
        );
        $st->execute([
            $data['name'], $data['descr'], $data['pid'], $data['enabled'], $data['account_id'],
            $data['num'], $data['direction'], $data['odds_type'],
            $data['payment_enabled'], $data['user_payment_enabled'], $data['supplier_enabled'],
            $data['linked_enabled'], $data['fund_enabled'], $data['exclude_pl'], $id,
        ]);
    }

    /** @param list<int> $ids @return int */
    public static function deleteByIds(array $ids): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static function (int $id): bool {
            return $id > 0;
        })));
        if ($ids === []) {
            return 0;
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = Database::pdo()->prepare("DELETE FROM base_account_move_types WHERE ID IN ($ph)");
        $st->execute($ids);
        return $st->rowCount();
    }

    public static function oddsLabel(int $odds): string
    {
        $key = 'ledger.types.odds.' . $odds;
        $label = t($key);
        return $label !== $key ? $label : (string)$odds;
    }
}
