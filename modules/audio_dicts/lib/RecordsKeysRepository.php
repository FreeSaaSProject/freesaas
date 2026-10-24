<?php
declare(strict_types=1);

final class RecordsKeysRepository
{
    /** @var list<array{id:int,name:string,id_dictonary:int,dict_name:string,norm:string}>|null */
    private static $overlapCatalog = null;

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
                       k.ID_DICTONARY AS id_dictonary, k.ID_LOOKLIKE AS id_looklike,
                       d.NAME AS dict_name
                FROM base_records_keys k
                LEFT JOIN base_records_dictonarys d ON d.ID = k.ID_DICTONARY
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
                    ID_DICTONARY AS id_dictonary, ID_LOOKLIKE AS id_looklike
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
            'id_dictonary' => max(0, (int)($post['id_dictonary'] ?? 0)),
            'id_looklike' => max(0, (int)($post['id_looklike'] ?? 0)),
        ];
    }

    /** @param array<string,mixed> $data */
    public static function create(array $data): int
    {
        $st = Database::pdo()->prepare(
            'INSERT INTO base_records_keys (DT_CREATE, NAME, DISABLED, ID_DICTONARY, ID_LOOKLIKE)
             VALUES (?,?,?,?,?)'
        );
        $st->execute([
            time(), $data['name'], $data['disabled'],
            $data['id_dictonary'], $data['id_looklike'],
        ]);
        self::clearOverlapCatalogCache();
        EventBus::emit('audio_dicts.changed', ['entity' => 'keys']);
        return (int)Database::pdo()->lastInsertId();
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data): void
    {
        $st = Database::pdo()->prepare(
            'UPDATE base_records_keys SET NAME=?, DISABLED=?, ID_DICTONARY=?, ID_LOOKLIKE=? WHERE ID=?'
        );
        $st->execute([
            $data['name'], $data['disabled'],
            $data['id_dictonary'], $data['id_looklike'], $id,
        ]);
        self::clearOverlapCatalogCache();
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
                    ID_DICTONARY AS id_dictonary, ID_LOOKLIKE AS id_looklike
             FROM base_records_keys WHERE NAME = ? LIMIT 1'
        );
        $st->execute([mb_substr($name, 0, 255)]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Normalize for overlap compare: lower case, ё→е, strip punctuation, collapse spaces.
     */
    public static function normalizeName(string $name): string
    {
        $name = mb_strtolower(trim($name), 'UTF-8');
        if ($name === '') {
            return '';
        }
        $name = str_replace(['ё', 'Ё'], ['е', 'е'], $name);
        $name = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $name) ?? $name;
        $name = preg_replace('/\s+/u', ' ', $name) ?? $name;
        return trim($name);
    }

    /**
     * Find keys that equal / contain / are contained in $name (broader than exact NAME=).
     * Oper-style nesting guard: new key must not sit inside another key and vice versa.
     *
     * @return list<array{
     *   id:int,name:string,id_dictonary:int,dict_name:string,
     *   relation:string,relation_label:string
     * }>
     */
    public static function findOverlaps(string $name, int $excludeId = 0, int $limit = 40): array
    {
        $needle = self::normalizeName($name);
        if ($needle === '' || !Database::tableExists('base_records_keys')) {
            return [];
        }
        $needleLen = mb_strlen($needle, 'UTF-8');
        if ($needleLen < 2) {
            return [];
        }

        if (self::$overlapCatalog === null) {
            self::$overlapCatalog = [];
            $sql = 'SELECT k.ID AS id, k.NAME AS name, k.ID_DICTONARY AS id_dictonary,
                           d.NAME AS dict_name
                    FROM base_records_keys k
                    LEFT JOIN base_records_dictonarys d ON d.ID = k.ID_DICTONARY
                    WHERE k.DISABLED < 1
                    LIMIT 12000';
            $st = Database::pdo()->query($sql);
            if ($st) {
                while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                    $norm = self::normalizeName((string)$row['name']);
                    if ($norm === '') {
                        continue;
                    }
                    self::$overlapCatalog[] = [
                        'id' => (int)$row['id'],
                        'name' => (string)$row['name'],
                        'id_dictonary' => (int)$row['id_dictonary'],
                        'dict_name' => (string)($row['dict_name'] ?? ''),
                        'norm' => $norm,
                    ];
                }
            }
        }

        $exact = [];
        $contains = []; // existing contains candidate
        $contained = []; // candidate contains existing
        foreach (self::$overlapCatalog as $row) {
            $id = (int)$row['id'];
            if ($excludeId > 0 && $id === $excludeId) {
                continue;
            }
            $other = (string)$row['norm'];
            $relation = '';
            if ($other === $needle) {
                $relation = 'exact';
            } elseif (mb_strpos($other, $needle, 0, 'UTF-8') !== false) {
                $relation = 'contains'; // other key is wider
            } elseif (mb_strpos($needle, $other, 0, 'UTF-8') !== false) {
                // Avoid trivial short fragments inside long phrases (1–2 chars already blocked).
                if (mb_strlen($other, 'UTF-8') >= 3) {
                    $relation = 'contained'; // new key is wider
                }
            }
            if ($relation === '') {
                continue;
            }
            $hit = [
                'id' => $id,
                'name' => (string)$row['name'],
                'id_dictonary' => (int)$row['id_dictonary'],
                'dict_name' => (string)$row['dict_name'],
                'relation' => $relation,
            ];
            if ($relation === 'exact') {
                $exact[] = $hit;
            } elseif ($relation === 'contains') {
                $contains[] = $hit;
            } else {
                $contained[] = $hit;
            }
            if (count($exact) + count($contains) + count($contained) >= $limit) {
                break;
            }
        }

        // Prefer exact, then "existing is wider", then "new is wider".
        return array_slice(array_merge($exact, $contains, $contained), 0, $limit);
    }

    /**
     * Soft nesting only: every hit is "contains" (an existing key already covers
     * this phrase as a substring). Safe to create without force — same rule as
     * coach apply / ASR short forms. Empty list counts as soft.
     *
     * @param list<array{relation?:string}> $overlaps
     */
    public static function isSoftContainsOnly(array $overlaps): bool
    {
        if ($overlaps === []) {
            return true;
        }
        foreach ($overlaps as $ov) {
            if ((string)($ov['relation'] ?? '') !== 'contains') {
                return false;
            }
        }

        return true;
    }

    /** Clear request-local overlap catalog (after create/update). */
    public static function clearOverlapCatalogCache(): void
    {
        self::$overlapCatalog = null;
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
