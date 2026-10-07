<?php
declare(strict_types=1);

final class BraceletsRepository
{
    /** @return array<string,string> */
    public static function listSortMap(): array
    {
        return [
            'id' => 'ID',
            'uid' => 'UID',
            'uid2' => 'UID2',
            'num' => 'NUM',
            'dt' => 'DT',
        ];
    }

    /**
     * @param array{q?:string,order?:string,limit?:int,offset?:int} $opts
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public static function list(array $opts = []): array
    {
        if (!Database::tableExists('base_bracelets')) {
            return ['items' => [], 'total' => 0];
        }
        $pdo = Database::pdo();
        $where = ['1=1'];
        $params = [];
        $q = trim((string)($opts['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(UID LIKE ? OR UID2 LIKE ? OR CAST(NUM AS CHAR) = ? OR CAST(ID AS CHAR) = ?)';
            $params[] = '%' . $q . '%';
            $params[] = '%' . $q . '%';
            $params[] = $q;
            $params[] = $q;
        }
        $sqlWhere = implode(' AND ', $where);
        $stc = $pdo->prepare("SELECT COUNT(*) FROM base_bracelets WHERE $sqlWhere");
        $stc->execute($params);
        $total = (int)$stc->fetchColumn();

        $order = trim((string)($opts['order'] ?? ''));
        if ($order === '') {
            $order = 'ID DESC';
        }
        $limit = max(0, (int)($opts['limit'] ?? 50));
        $offset = max(0, (int)($opts['offset'] ?? 0));
        $sql = "SELECT ID AS id, DT AS dt, UID AS uid, UID2 AS uid2, NUM AS num
                FROM base_bracelets WHERE $sqlWhere ORDER BY $order";
        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return ['items' => $st->fetchAll(PDO::FETCH_ASSOC) ?: [], 'total' => $total];
    }

    public static function find(int $id): ?array
    {
        if ($id < 1 || !Database::tableExists('base_bracelets')) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, DT AS dt, UID AS uid, UID2 AS uid2, NUM AS num
             FROM base_bracelets WHERE ID = ? LIMIT 1'
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
        $uid = trim((string)($post['uid'] ?? ''));
        $uid2 = trim((string)($post['uid2'] ?? ''));
        if ($uid === '' && $uid2 === '') {
            throw new InvalidArgumentException(t('terminals.err.bracelet_uid_required'));
        }
        if (mb_strlen($uid) > 20 || mb_strlen($uid2) > 20) {
            throw new InvalidArgumentException(t('terminals.err.bracelet_uid_too_long'));
        }
        return [
            'uid' => $uid,
            'uid2' => $uid2,
            'num' => max(0, (int)($post['num'] ?? 0)),
        ];
    }

    /** @param array<string,mixed> $data */
    public static function create(array $data): int
    {
        $st = Database::pdo()->prepare(
            'INSERT INTO base_bracelets (DT, UID, UID2, NUM) VALUES (?, ?, ?, ?)'
        );
        $st->execute([time(), $data['uid'], $data['uid2'], (int)$data['num']]);
        return (int)Database::pdo()->lastInsertId();
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data): void
    {
        if ($id < 1) {
            throw new InvalidArgumentException('Invalid bracelet id');
        }
        $st = Database::pdo()->prepare(
            'UPDATE base_bracelets SET UID = ?, UID2 = ?, NUM = ? WHERE ID = ?'
        );
        $st->execute([$data['uid'], $data['uid2'], (int)$data['num'], $id]);
    }

    /**
     * @param list<int> $ids
     */
    public static function deleteByIds(array $ids): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static function (int $id): bool {
            return $id > 0;
        })));
        if ($ids === [] || !Database::tableExists('base_bracelets')) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $st = Database::pdo()->prepare("DELETE FROM base_bracelets WHERE ID IN ($placeholders)");
        $st->execute($ids);
        return $st->rowCount();
    }
}
