<?php
declare(strict_types=1);

final class RecordsKeysRepository
{
    /** @return array<string,string> */
    public static function listSortMap(): array
    {
        return ['id' => 'ID', 'name' => 'NAME', 'disabled' => 'DISABLED', 'dict' => 'ID_DICTONARY'];
    }

    /**
     * @param array{q?:string,dict?:int,order?:string,limit?:int,offset?:int} $opts
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public static function list(array $opts = []): array
    {
        if (!Database::tableExists('base_records_keys')) {
            return ['items' => [], 'total' => 0];
        }
        $pdo = Database::pdo();
        $where = ['1=1'];
        $params = [];
        $q = trim((string)($opts['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(k.NAME LIKE ? OR CAST(k.ID AS CHAR) = ?)';
            $params[] = '%' . $q . '%';
            $params[] = $q;
        }
        $dict = (int)($opts['dict'] ?? 0);
        if ($dict > 0) {
            $where[] = 'k.ID_DICTONARY = ?';
            $params[] = $dict;
        }
        $sqlWhere = implode(' AND ', $where);
        $stc = $pdo->prepare("SELECT COUNT(*) FROM base_records_keys k WHERE $sqlWhere");
        $stc->execute($params);
        $total = (int)$stc->fetchColumn();
        $order = trim((string)($opts['order'] ?? '')) ?: 'k.ID DESC';
        $limit = max(0, (int)($opts['limit'] ?? 50));
        $offset = max(0, (int)($opts['offset'] ?? 0));
        $sql = "SELECT k.ID AS id, k.DT_CREATE AS dt_create, k.NAME AS name, k.DISABLED AS disabled,
                       k.ID_TYPE AS id_type, k.ID_DICTONARY AS id_dictonary, k.ID_LOOKLIKE AS id_looklike,
                       d.NAME AS dict_name, t.NAME AS type_name
                FROM base_records_keys k
                LEFT JOIN base_records_dictonarys d ON d.ID = k.ID_DICTONARY
                LEFT JOIN base_records_types t ON t.ID = k.ID_TYPE
                WHERE $sqlWhere ORDER BY $order";
        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return ['items' => $st->fetchAll(PDO::FETCH_ASSOC) ?: [], 'total' => $total];
    }

    public static function find(int $id): ?array
    {
        if ($id < 1 || !Database::tableExists('base_records_keys')) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, DT_CREATE AS dt_create, NAME AS name, DISABLED AS disabled,
                    ID_TYPE AS id_type, ID_DICTONARY AS id_dictonary, ID_LOOKLIKE AS id_looklike
             FROM base_records_keys WHERE ID = ? LIMIT 1'
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
            throw new InvalidArgumentException(t('audio_dicts.err.name_required'));
        }
        return [
            'name' => mb_substr($name, 0, 255),
            'disabled' => !empty($post['disabled']) ? 1 : 0,
            'id_type' => max(0, (int)($post['id_type'] ?? 0)),
            'id_dictonary' => max(0, (int)($post['id_dictonary'] ?? 0)),
            'id_looklike' => max(0, (int)($post['id_looklike'] ?? 0)),
        ];
    }

    /** @param array<string,mixed> $data */
    public static function create(array $data): int
    {
        $st = Database::pdo()->prepare(
            'INSERT INTO base_records_keys (ID_TYPE, DT_CREATE, NAME, DISABLED, ID_DICTONARY, ID_LOOKLIKE)
             VALUES (?,?,?,?,?,?)'
        );
        $st->execute([
            $data['id_type'], time(), $data['name'], $data['disabled'],
            $data['id_dictonary'], $data['id_looklike'],
        ]);
        EventBus::emit('audio_dicts.changed', ['entity' => 'keys']);
        return (int)Database::pdo()->lastInsertId();
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data): void
    {
        $st = Database::pdo()->prepare(
            'UPDATE base_records_keys SET ID_TYPE=?, NAME=?, DISABLED=?, ID_DICTONARY=?, ID_LOOKLIKE=? WHERE ID=?'
        );
        $st->execute([
            $data['id_type'], $data['name'], $data['disabled'],
            $data['id_dictonary'], $data['id_looklike'], $id,
        ]);
        EventBus::emit('audio_dicts.changed', ['entity' => 'keys', 'id' => $id]);
    }

    public static function findByName(string $name): ?array
    {
        $name = trim($name);
        if ($name === '' || !Database::tableExists('base_records_keys')) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, DT_CREATE AS dt_create, NAME AS name, DISABLED AS disabled,
                    ID_TYPE AS id_type, ID_DICTONARY AS id_dictonary, ID_LOOKLIKE AS id_looklike
             FROM base_records_keys WHERE NAME = ? LIMIT 1'
        );
        $st->execute([mb_substr($name, 0, 255)]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** @param list<int> $ids */
    public static function deleteByIds(array $ids): int
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn ($i) => $i > 0));
        if (!$ids) {
            return 0;
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = Database::pdo()->prepare("DELETE FROM base_records_keys WHERE ID IN ($in)");
        $st->execute($ids);
        EventBus::emit('audio_dicts.changed', ['entity' => 'keys']);
        return $st->rowCount();
    }
}
