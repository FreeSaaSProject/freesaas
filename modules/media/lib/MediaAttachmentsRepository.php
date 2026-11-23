<?php
declare(strict_types=1);

final class MediaAttachmentsRepository
{
    public const OWNER_PROCEDURES = 'base_procedures';

    /**
     * @param array{
     *   owner_table?:string,
     *   owner_id?:int|null,
     *   kind?:string,
     *   id_user?:int|null,
     *   q?:string,
     *   limit?:int,
     *   offset?:int
     * } $opts
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public static function list(array $opts = []): array
    {
        if (!Database::tableExists('base_media_attachments')) {
            return ['items' => [], 'total' => 0];
        }
        $where = ['1=1'];
        $params = [];
        $ownerTable = trim((string)($opts['owner_table'] ?? ''));
        if ($ownerTable !== '') {
            $where[] = 'a.OWNER_TABLE = ?';
            $params[] = $ownerTable;
        }
        $ownerId = (int)($opts['owner_id'] ?? 0);
        if ($ownerId > 0) {
            $where[] = 'a.OWNER_ID = ?';
            $params[] = $ownerId;
        }
        $kind = trim((string)($opts['kind'] ?? ''));
        if ($kind !== '' && in_array($kind, MediaConfig::kinds(), true)) {
            $where[] = 'a.KIND = ?';
            $params[] = $kind;
        }
        $idUser = (int)($opts['id_user'] ?? 0);
        $join = '';
        if ($idUser > 0) {
            $join = ' INNER JOIN base_procedures p ON a.OWNER_TABLE = \'base_procedures\' AND a.OWNER_ID = p.ID ';
            $where[] = 'p.ID_USER = ?';
            $params[] = $idUser;
        }
        $q = trim((string)($opts['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(a.ORIGINAL_NAME LIKE ? OR CAST(a.ID AS CHAR) = ? OR CAST(a.OWNER_ID AS CHAR) = ?)';
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $q;
            $params[] = $q;
        }
        $sqlWhere = implode(' AND ', $where);
        $stc = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM base_media_attachments a' . $join . ' WHERE ' . $sqlWhere
        );
        $stc->execute($params);
        $total = (int)$stc->fetchColumn();
        $limit = max(1, min(200, (int)($opts['limit'] ?? 50)));
        $offset = max(0, (int)($opts['offset'] ?? 0));
        $st = Database::pdo()->prepare(
            'SELECT a.ID AS id, a.DT_CREATE AS dt_create, a.ID_AUTHOR AS id_author,
                    a.OWNER_TABLE AS owner_table, a.OWNER_ID AS owner_id, a.KIND AS kind,
                    a.STORAGE_PATH AS storage_path, a.THUMB_PATH AS thumb_path,
                    a.MIME AS mime, a.BYTES AS bytes, a.WIDTH AS width, a.HEIGHT AS height,
                    a.ORIGINAL_NAME AS original_name
             FROM base_media_attachments a' . $join . '
             WHERE ' . $sqlWhere . '
             ORDER BY a.ID DESC
             LIMIT ' . $limit . ' OFFSET ' . $offset
        );
        $st->execute($params);
        $items = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return ['items' => $items, 'total' => $total];
    }

    /** @return list<array<string,mixed>> */
    public static function listForOwner(string $ownerTable, int $ownerId): array
    {
        $r = self::list([
            'owner_table' => $ownerTable,
            'owner_id' => $ownerId,
            'limit' => 200,
            'offset' => 0,
        ]);
        return $r['items'];
    }

    public static function find(int $id): ?array
    {
        if ($id < 1 || !Database::tableExists('base_media_attachments')) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, DT_CREATE AS dt_create, ID_AUTHOR AS id_author,
                    OWNER_TABLE AS owner_table, OWNER_ID AS owner_id, KIND AS kind,
                    STORAGE_PATH AS storage_path, THUMB_PATH AS thumb_path,
                    MIME AS mime, BYTES AS bytes, WIDTH AS width, HEIGHT AS height,
                    ORIGINAL_NAME AS original_name, META_JSON AS meta_json
             FROM base_media_attachments WHERE ID = ? LIMIT 1'
        );
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function create(array $data): int
    {
        $st = Database::pdo()->prepare(
            'INSERT INTO base_media_attachments
             (DT_CREATE, ID_AUTHOR, OWNER_TABLE, OWNER_ID, KIND, STORAGE_PATH, THUMB_PATH,
              MIME, BYTES, WIDTH, HEIGHT, ORIGINAL_NAME, META_JSON)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $st->execute([
            time(),
            max(0, (int)($data['id_author'] ?? 0)),
            (string)$data['owner_table'],
            max(0, (int)$data['owner_id']),
            (string)$data['kind'],
            (string)$data['storage_path'],
            (string)($data['thumb_path'] ?? ''),
            (string)($data['mime'] ?? ''),
            max(0, (int)($data['bytes'] ?? 0)),
            max(0, (int)($data['width'] ?? 0)),
            max(0, (int)($data['height'] ?? 0)),
            mb_substr((string)($data['original_name'] ?? ''), 0, 255),
            isset($data['meta_json']) ? (string)$data['meta_json'] : null,
        ]);
        return (int)Database::pdo()->lastInsertId();
    }

    public static function deleteByIds(array $ids): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static function (int $id): bool {
            return $id > 0;
        })));
        if ($ids === [] || !Database::tableExists('base_media_attachments')) {
            return 0;
        }
        $store = MediaStore::make();
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $st = Database::pdo()->prepare(
            "SELECT ID, STORAGE_PATH, THUMB_PATH FROM base_media_attachments WHERE ID IN ($placeholders)"
        );
        $st->execute($ids);
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $store->deletePaths((string)$row['STORAGE_PATH'], (string)$row['THUMB_PATH']);
        }
        $del = Database::pdo()->prepare("DELETE FROM base_media_attachments WHERE ID IN ($placeholders)");
        $del->execute($ids);
        return $del->rowCount();
    }

    /** @return array<string,int> kind => count */
    public static function countsByKind(string $ownerTable, int $ownerId): array
    {
        if ($ownerId < 1 || !Database::tableExists('base_media_attachments')) {
            return [];
        }
        $st = Database::pdo()->prepare(
            'SELECT KIND AS kind, COUNT(*) AS c FROM base_media_attachments
             WHERE OWNER_TABLE = ? AND OWNER_ID = ? GROUP BY KIND'
        );
        $st->execute([$ownerTable, $ownerId]);
        $out = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $out[(string)$row['kind']] = (int)$row['c'];
        }
        return $out;
    }
}
