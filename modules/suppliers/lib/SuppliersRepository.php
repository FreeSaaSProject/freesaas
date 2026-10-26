<?php
declare(strict_types=1);

final class SuppliersRepository
{
    /** @return array<string,string> */
    public static function listSortMap(): array
    {
        return [
            'id' => 'ID',
            'name' => 'NAME',
            'enabled' => 'ENABLED',
            'inn' => 'INN',
            'is_goods_sup' => 'IS_GOODS_SUP',
        ];
    }

    /**
     * @param array{q?:string,enabled?:string|null,order?:string,limit?:int,offset?:int} $opts
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public static function list(array $opts = []): array
    {
        if (!Database::tableExists('base_suppliers')) {
            return ['items' => [], 'total' => 0];
        }
        $pdo = Database::pdo();
        $where = ['1=1'];
        $params = [];
        $q = trim((string)($opts['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(NAME LIKE ? OR UR_NAME LIKE ? OR INN LIKE ? OR CAST(ID AS CHAR) = ?)';
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $q;
        }
        if (array_key_exists('enabled', $opts) && $opts['enabled'] !== null && $opts['enabled'] !== '') {
            $where[] = 'ENABLED = ?';
            $params[] = (int)$opts['enabled'];
        }
        $sqlWhere = implode(' AND ', $where);
        $stc = $pdo->prepare("SELECT COUNT(*) FROM base_suppliers WHERE $sqlWhere");
        $stc->execute($params);
        $total = (int)$stc->fetchColumn();

        $order = trim((string)($opts['order'] ?? ''));
        if ($order === '') {
            $order = 'NAME ASC';
        }
        $limit = max(0, (int)($opts['limit'] ?? 50));
        $offset = max(0, (int)($opts['offset'] ?? 0));
        $sql = "SELECT ID AS id, NAME AS name, DESCR AS descr, ENABLED AS enabled,
                       DOGOVOR AS dogovor, UR_NAME AS ur_name, INN AS inn, KPP AS kpp,
                       RS AS rs, KS AS ks, BIK AS bik, BANK AS bank, DT AS dt,
                       SUMM_FREE_DELIV AS summ_free_deliv, CONTACT_DATA AS contact_data,
                       IS_GOODS_SUP AS is_goods_sup
                FROM base_suppliers WHERE $sqlWhere ORDER BY $order";
        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return ['items' => $st->fetchAll(PDO::FETCH_ASSOC) ?: [], 'total' => $total];
    }

    /** @return array<int,string> */
    public static function names(bool $enabledOnly = true): array
    {
        if (!Database::tableExists('base_suppliers')) {
            return [];
        }
        $sql = 'SELECT ID, NAME FROM base_suppliers';
        if ($enabledOnly) {
            $sql .= ' WHERE ENABLED = 1';
        }
        $sql .= ' ORDER BY NAME ASC';
        $out = [];
        foreach (Database::pdo()->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $out[(int)$r['ID']] = (string)$r['NAME'];
        }
        return $out;
    }

    /**
     * Match counterparty by INN digits (10 or 12). Prefers ENABLED=1.
     * Returns 0 if none or ambiguous (multiple distinct ids).
     */
    public static function findIdByInn(string $inn): int
    {
        if (!Database::tableExists('base_suppliers')) {
            return 0;
        }
        $digits = preg_replace('/\D+/', '', trim($inn)) ?? '';
        if (strlen($digits) !== 10 && strlen($digits) !== 12) {
            return 0;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, ENABLED AS enabled
             FROM base_suppliers
             WHERE REPLACE(REPLACE(REPLACE(INN, \' \', \'\'), \'-\', \'\'), \'\t\', \'\') = ?
             ORDER BY ENABLED DESC, ID ASC
             LIMIT 5'
        );
        $st->execute([$digits]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($rows === []) {
            return 0;
        }
        $enabled = [];
        foreach ($rows as $r) {
            if ((int)($r['enabled'] ?? 0) === 1) {
                $enabled[] = (int)$r['id'];
            }
        }
        if (count($enabled) === 1) {
            return $enabled[0];
        }
        if (count($enabled) > 1) {
            return 0; // ambiguous
        }
        // only disabled rows
        return count($rows) === 1 ? (int)$rows[0]['id'] : 0;
    }

    public static function find(int $id): ?array
    {
        if ($id < 1 || !Database::tableExists('base_suppliers')) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, NAME AS name, DESCR AS descr, ENABLED AS enabled,
                    DOGOVOR AS dogovor, UR_NAME AS ur_name, INN AS inn, KPP AS kpp,
                    RS AS rs, KS AS ks, BIK AS bik, BANK AS bank, DT AS dt,
                    SUMM_FREE_DELIV AS summ_free_deliv, CONTACT_DATA AS contact_data,
                    IS_GOODS_SUP AS is_goods_sup
             FROM base_suppliers WHERE ID = ? LIMIT 1'
        );
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * @param array<string,mixed> $post
     * @return array<string,mixed>
     */
    public static function dataFromPost(array $post): array
    {
        $name = trim((string)($post['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException(t('suppliers.err.name_required'));
        }
        if (mb_strlen($name) > 40) {
            throw new InvalidArgumentException(t('suppliers.err.name_too_long'));
        }
        return [
            'name' => $name,
            'descr' => mb_substr(trim((string)($post['descr'] ?? '')), 0, 255),
            'enabled' => !empty($post['enabled']) ? 1 : 0,
            'dogovor' => mb_substr(trim((string)($post['dogovor'] ?? '')), 0, 20),
            'ur_name' => mb_substr(trim((string)($post['ur_name'] ?? '')), 0, 255),
            'inn' => mb_substr(trim((string)($post['inn'] ?? '')), 0, 40),
            'kpp' => mb_substr(trim((string)($post['kpp'] ?? '')), 0, 40),
            'rs' => mb_substr(trim((string)($post['rs'] ?? '')), 0, 40),
            'ks' => mb_substr(trim((string)($post['ks'] ?? '')), 0, 40),
            'bik' => mb_substr(trim((string)($post['bik'] ?? '')), 0, 40),
            'bank' => mb_substr(trim((string)($post['bank'] ?? '')), 0, 40),
            'summ_free_deliv' => max(0, (int)($post['summ_free_deliv'] ?? 0)),
            'contact_data' => mb_substr(trim((string)($post['contact_data'] ?? '')), 0, 255),
            'is_goods_sup' => !empty($post['is_goods_sup']) ? 1 : 0,
        ];
    }

    /** @param array<string,mixed> $data */
    public static function create(array $data): int
    {
        $st = Database::pdo()->prepare(
            'INSERT INTO base_suppliers
             (NAME, DESCR, ENABLED, DOGOVOR, UR_NAME, INN, KPP, RS, KS, BIK, BANK, DT,
              SUMM_FREE_DELIV, CONTACT_DATA, IS_GOODS_SUP)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $st->execute([
            $data['name'],
            $data['descr'],
            $data['enabled'],
            $data['dogovor'],
            $data['ur_name'],
            $data['inn'],
            $data['kpp'],
            $data['rs'],
            $data['ks'],
            $data['bik'],
            $data['bank'],
            time(),
            $data['summ_free_deliv'],
            $data['contact_data'],
            $data['is_goods_sup'],
        ]);
        return (int)Database::pdo()->lastInsertId();
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data): void
    {
        if ($id < 1) {
            throw new InvalidArgumentException('Invalid supplier id');
        }
        $st = Database::pdo()->prepare(
            'UPDATE base_suppliers SET
             NAME = ?, DESCR = ?, ENABLED = ?, DOGOVOR = ?, UR_NAME = ?, INN = ?, KPP = ?,
             RS = ?, KS = ?, BIK = ?, BANK = ?, SUMM_FREE_DELIV = ?, CONTACT_DATA = ?,
             IS_GOODS_SUP = ?
             WHERE ID = ?'
        );
        $st->execute([
            $data['name'],
            $data['descr'],
            $data['enabled'],
            $data['dogovor'],
            $data['ur_name'],
            $data['inn'],
            $data['kpp'],
            $data['rs'],
            $data['ks'],
            $data['bik'],
            $data['bank'],
            $data['summ_free_deliv'],
            $data['contact_data'],
            $data['is_goods_sup'],
            $id,
        ]);
    }

    /**
     * @param list<int> $ids
     * @return int deleted count
     */
    public static function deleteByIds(array $ids): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static function (int $id): bool {
            return $id > 0;
        })));
        if ($ids === [] || !Database::tableExists('base_suppliers')) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $st = Database::pdo()->prepare("DELETE FROM base_suppliers WHERE ID IN ($placeholders)");
        $st->execute($ids);
        return $st->rowCount();
    }
}
