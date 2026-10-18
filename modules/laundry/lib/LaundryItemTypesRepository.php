<?php
declare(strict_types=1);

final class LaundryItemTypesRepository
{
    /** @return array<string,string> */
    public static function listSortMap(): array
    {
        return ['id' => 'ID', 'code' => 'CODE', 'name' => 'NAME', 'sort' => 'SORT', 'enabled' => 'ENABLED'];
    }

    /** @return list<array{id:int,code:string,name:string,sort:int,enabled:int}> */
    public static function listEnabled(): array
    {
        return self::list(['enabled' => '1', 'limit' => 0])['items'];
    }

    /**
     * @param array{q?:string,enabled?:string|null,order?:string,limit?:int,offset?:int} $opts
     * @return array{items:list<array{id:int,code:string,name:string,sort:int,enabled:int}>,total:int}
     */
    public static function list(array $opts = []): array
    {
        if (!Database::tableExists('base_laundry_item_types')) {
            return ['items' => [], 'total' => 0];
        }
        $pdo = Database::pdo();
        $where = ['1=1'];
        $params = [];
        $q = trim((string)($opts['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(CODE LIKE ? OR NAME LIKE ? OR CAST(ID AS CHAR) = ?)';
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $q;
        }
        if (array_key_exists('enabled', $opts) && $opts['enabled'] !== null && $opts['enabled'] !== '') {
            $where[] = 'ENABLED = ?';
            $params[] = (int)$opts['enabled'];
        }
        $sqlWhere = implode(' AND ', $where);
        $stc = $pdo->prepare("SELECT COUNT(*) FROM base_laundry_item_types WHERE $sqlWhere");
        $stc->execute($params);
        $total = (int)$stc->fetchColumn();
        $order = trim((string)($opts['order'] ?? '')) ?: 'SORT ASC, ID ASC';
        $limit = max(0, (int)($opts['limit'] ?? 50));
        $offset = max(0, (int)($opts['offset'] ?? 0));
        $sql = "SELECT ID AS id, CODE AS code, NAME AS name, SORT AS sort, ENABLED AS enabled
                FROM base_laundry_item_types WHERE $sqlWhere ORDER BY $order";
        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $items = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($items as &$r) {
            $r['id'] = (int)$r['id'];
            $r['sort'] = (int)$r['sort'];
            $r['enabled'] = (int)$r['enabled'];
        }
        unset($r);
        return ['items' => $items, 'total' => $total];
    }

    /** @return array<int,string> */
    public static function names(bool $enabledOnly = true): array
    {
        $out = [];
        foreach (self::list(['enabled' => $enabledOnly ? '1' : null, 'limit' => 0])['items'] as $r) {
            $out[(int)$r['id']] = (string)$r['name'];
        }
        return $out;
    }

    public static function find(int $id): ?array
    {
        if ($id < 1 || !Database::tableExists('base_laundry_item_types')) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, CODE AS code, NAME AS name, SORT AS sort, ENABLED AS enabled
             FROM base_laundry_item_types WHERE ID = ? LIMIT 1'
        );
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $row['id'] = (int)$row['id'];
        $row['sort'] = (int)$row['sort'];
        $row['enabled'] = (int)$row['enabled'];
        return $row;
    }

    /** @param array<string,mixed> $post */
    public static function dataFromPost(array $post): array
    {
        $name = trim((string)($post['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException(t('laundry.err.name_required'));
        }
        $code = trim((string)($post['code'] ?? ''));
        if ($code === '') {
            $code = self::slugFromName($name);
        }
        $code = mb_strtolower(preg_replace('~[^a-zA-Z0-9_]+~', '_', $code) ?? '');
        $code = trim($code, '_');
        if ($code === '') {
            throw new InvalidArgumentException(t('laundry.err.code_required'));
        }
        return [
            'code' => mb_substr($code, 0, 30),
            'name' => mb_substr($name, 0, 60),
            'sort' => (int)($post['sort'] ?? 0),
            'enabled' => !empty($post['enabled']) ? 1 : 0,
        ];
    }

    private static function slugFromName(string $name): string
    {
        $map = [
            'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'e',
            'ж' => 'zh', 'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm',
            'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u',
            'ф' => 'f', 'х' => 'h', 'ц' => 'c', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'sch', 'ъ' => '',
            'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
        ];
        $low = mb_strtolower($name);
        $out = '';
        $len = mb_strlen($low);
        for ($i = 0; $i < $len; $i++) {
            $ch = mb_substr($low, $i, 1);
            if (isset($map[$ch])) {
                $out .= $map[$ch];
            } elseif (preg_match('~[a-z0-9]~', $ch)) {
                $out .= $ch;
            } else {
                $out .= '_';
            }
        }
        return trim(preg_replace('~_+~', '_', $out) ?? '', '_');
    }

    /** @param array<string,mixed> $data */
    public static function create(array $data): int
    {
        self::assertCodeUnique((string)$data['code'], 0);
        $st = Database::pdo()->prepare(
            'INSERT INTO base_laundry_item_types (CODE, NAME, SORT, ENABLED) VALUES (?, ?, ?, ?)'
        );
        $st->execute([$data['code'], $data['name'], $data['sort'], $data['enabled']]);
        return (int)Database::pdo()->lastInsertId();
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data): void
    {
        self::assertCodeUnique((string)$data['code'], $id);
        Database::pdo()->prepare(
            'UPDATE base_laundry_item_types SET CODE = ?, NAME = ?, SORT = ?, ENABLED = ? WHERE ID = ?'
        )->execute([$data['code'], $data['name'], $data['sort'], $data['enabled'], $id]);
    }

    private static function assertCodeUnique(string $code, int $exceptId): void
    {
        $st = Database::pdo()->prepare(
            'SELECT ID FROM base_laundry_item_types WHERE CODE = ? AND ID <> ? LIMIT 1'
        );
        $st->execute([$code, $exceptId]);
        if ($st->fetchColumn()) {
            throw new InvalidArgumentException(t('laundry.err.code_taken', ['code' => $code]));
        }
    }

    /** @param list<int> $ids */
    public static function deleteByIds(array $ids): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === []) {
            return 0;
        }
        // Block delete if used in moves or shipment lines
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $pdo = Database::pdo();
        if (Database::tableExists('base_laundry_moves')) {
            $st = $pdo->prepare("SELECT ID_ITEM_TYPE FROM base_laundry_moves WHERE ID_ITEM_TYPE IN ($ph) AND DELETED = 0 LIMIT 1");
            $st->execute($ids);
            if ($st->fetchColumn()) {
                throw new InvalidArgumentException(t('laundry.err.type_in_use'));
            }
        }
        if (Database::tableExists('base_laundry_shipment_lines')) {
            $st = $pdo->prepare("SELECT ID_ITEM_TYPE FROM base_laundry_shipment_lines WHERE ID_ITEM_TYPE IN ($ph) LIMIT 1");
            $st->execute($ids);
            if ($st->fetchColumn()) {
                throw new InvalidArgumentException(t('laundry.err.type_in_use'));
            }
        }
        $st = $pdo->prepare("DELETE FROM base_laundry_item_types WHERE ID IN ($ph)");
        $st->execute($ids);
        return $st->rowCount();
    }
}
