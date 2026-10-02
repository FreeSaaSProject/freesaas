<?php
declare(strict_types=1);

final class PaymentTypesRepository
{
    /** @return array<string,string> */
    public static function listSortMap(): array
    {
        return [
            'id' => 'ID',
            'name' => 'NAME',
            'descr' => 'DESCR',
        ];
    }

    /**
     * @param array{q?:string,order?:string,limit?:int,offset?:int} $opts
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public static function list(array $opts = []): array
    {
        if (!Database::tableExists('base_payment_types')) {
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
        $stc = $pdo->prepare("SELECT COUNT(*) FROM base_payment_types WHERE $sqlWhere");
        $stc->execute($params);
        $total = (int)$stc->fetchColumn();
        $order = trim((string)($opts['order'] ?? ''));
        if ($order === '') {
            $order = 'ID ASC';
        }
        $limit = max(0, (int)($opts['limit'] ?? 50));
        $offset = max(0, (int)($opts['offset'] ?? 0));
        $sql = "SELECT ID AS id, NAME AS name, DESCR AS descr, COLOR AS color, DT AS dt, ID_USER AS user_id
                FROM base_payment_types WHERE $sqlWhere ORDER BY $order";
        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return ['items' => $st->fetchAll(PDO::FETCH_ASSOC) ?: [], 'total' => $total];
    }

    /** @return array<int,string> */
    public static function names(): array
    {
        if (!Database::tableExists('base_payment_types')) {
            return [];
        }
        $out = [];
        foreach (Database::pdo()->query(
            'SELECT ID, NAME, DESCR FROM base_payment_types ORDER BY ID ASC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $label = trim((string)$r['NAME']);
            $descr = trim((string)($r['DESCR'] ?? ''));
            if ($descr !== '') {
                $label .= ($label !== '' ? ' — ' : '') . $descr;
            }
            $out[(int)$r['ID']] = $label !== '' ? $label : ('#' . (int)$r['ID']);
        }
        return $out;
    }

    public static function find(int $id): ?array
    {
        if ($id < 1 || !Database::tableExists('base_payment_types')) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, NAME AS name, DESCR AS descr, COLOR AS color, DT AS dt, ID_USER AS user_id
             FROM base_payment_types WHERE ID = ? LIMIT 1'
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
            throw new InvalidArgumentException(t('payments.types.err.name'));
        }
        if (mb_strlen($name) > 5) {
            $name = mb_substr($name, 0, 5);
        }
        return [
            'name' => $name,
            'descr' => trim((string)($post['descr'] ?? '')),
            'color' => trim((string)($post['color'] ?? '')),
            'dt' => time(),
            'user_id' => Auth::userId(),
        ];
    }

    /** @param array<string,mixed> $data */
    public static function create(array $data): int
    {
        $st = Database::pdo()->prepare(
            'INSERT INTO base_payment_types (NAME, DESCR, COLOR, DT, ID_USER) VALUES (?, ?, ?, ?, ?)'
        );
        $st->execute([$data['name'], $data['descr'], $data['color'], $data['dt'], $data['user_id']]);
        return (int)Database::pdo()->lastInsertId();
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data): void
    {
        $st = Database::pdo()->prepare(
            'UPDATE base_payment_types SET NAME = ?, DESCR = ?, COLOR = ? WHERE ID = ?'
        );
        $st->execute([$data['name'], $data['descr'], $data['color'], $id]);
    }

    /** @param list<int> $ids @return int */
    public static function deleteByIds(array $ids): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static function (int $id): bool {
        return $id > 0;
    })));
        if ($ids === [] || !Database::tableExists('base_payment_types')) {
            return 0;
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = Database::pdo()->prepare("DELETE FROM base_payment_types WHERE ID IN ($ph)");
        $st->execute($ids);
        return $st->rowCount();
    }
}
