<?php
declare(strict_types=1);

final class LedgerAccountsRepository
{
    /** @return array<string,string> */
    public static function listSortMap(): array
    {
        return [
            'id' => 'ID',
            'name' => 'NAME',
            'enabled' => 'ENABLED',
            'type' => 'ID_TYPE',
        ];
    }

    /**
     * @param array{q?:string,order?:string,limit?:int,offset?:int} $opts
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public static function list(array $opts = []): array
    {
        if (!Database::tableExists('base_accounts')) {
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
        $stc = $pdo->prepare("SELECT COUNT(*) FROM base_accounts WHERE $sqlWhere");
        $stc->execute($params);
        $total = (int)$stc->fetchColumn();
        $order = trim((string)($opts['order'] ?? '')) ?: 'NAME ASC';
        $limit = max(0, (int)($opts['limit'] ?? 50));
        $offset = max(0, (int)($opts['offset'] ?? 0));
        $sql = "SELECT ID AS id, NAME AS name, DESCR AS descr, DT AS dt,
                       ENABLED AS enabled, ID_TYPE AS id_type
                FROM base_accounts WHERE $sqlWhere ORDER BY $order";
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
        if (!Database::tableExists('base_accounts')) {
            return [];
        }
        $sql = 'SELECT ID, NAME FROM base_accounts';
        if ($enabledOnly) {
            $sql .= ' WHERE ENABLED > 0';
        }
        $sql .= ' ORDER BY NAME ASC';
        $out = [];
        foreach (Database::pdo()->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $out[(int)$r['ID']] = (string)$r['NAME'];
        }
        return $out;
    }

    public static function find(int $id): ?array
    {
        if ($id < 1 || !Database::tableExists('base_accounts')) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, NAME AS name, DESCR AS descr, DT AS dt,
                    ENABLED AS enabled, ID_TYPE AS id_type
             FROM base_accounts WHERE ID = ? LIMIT 1'
        );
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** @param array<string,mixed> $post @return array<string,mixed> */
    public static function dataFromPost(array $post): array
    {
        $name = trim((string)($post['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException(t('ledger.accounts.err.name'));
        }
        if (mb_strlen($name) > 20) {
            $name = mb_substr($name, 0, 20);
        }
        $idType = (int)($post['id_type'] ?? 2);
        if (!in_array($idType, [1, 2], true)) {
            $idType = 2;
        }
        return [
            'name' => $name,
            'descr' => trim((string)($post['descr'] ?? '')),
            'enabled' => !empty($post['enabled']) ? 1 : 0,
            'id_type' => $idType,
            'dt' => time(),
        ];
    }

    /** @param array<string,mixed> $data */
    public static function create(array $data): int
    {
        $st = Database::pdo()->prepare(
            'INSERT INTO base_accounts (NAME, DESCR, DT, ENABLED, ID_TYPE) VALUES (?, ?, ?, ?, ?)'
        );
        $st->execute([$data['name'], $data['descr'], $data['dt'], $data['enabled'], $data['id_type']]);
        return (int)Database::pdo()->lastInsertId();
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data): void
    {
        $st = Database::pdo()->prepare(
            'UPDATE base_accounts SET NAME = ?, DESCR = ?, ENABLED = ?, ID_TYPE = ? WHERE ID = ?'
        );
        $st->execute([$data['name'], $data['descr'], $data['enabled'], $data['id_type'], $id]);
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
        $st = Database::pdo()->prepare("DELETE FROM base_accounts WHERE ID IN ($ph)");
        $st->execute($ids);
        return $st->rowCount();
    }

    public static function typeLabel(int $type): string
    {
        $key = 'ledger.accounts.type.' . $type;
        $label = t($key);
        return $label !== $key ? $label : (string)$type;
    }
}
