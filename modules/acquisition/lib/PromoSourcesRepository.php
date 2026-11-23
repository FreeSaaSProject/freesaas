<?php
declare(strict_types=1);

/** Marketing / acquisition channel dictionary (beautime base_promo_sources). */
final class PromoSourcesRepository
{
    public static function tableReady(): bool
    {
        return Database::tableExists('base_promo_sources');
    }

    /** @return list<array<string,mixed>> */
    public static function listEnabledForClients(): array
    {
        if (!self::tableReady()) {
            return [];
        }
        $st = Database::pdo()->query(
            'SELECT ID AS id, NAME AS name, SRC_ENABLED AS src_enabled, ENABLED AS enabled
             FROM base_promo_sources
             WHERE ENABLED > 0 AND SRC_ENABLED > 0
             ORDER BY NAME ASC'
        );
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $out[] = self::cast($row);
        }
        return $out;
    }

    /**
     * @param array{q?:string,enabled?:string} $opts
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public static function list(array $opts = []): array
    {
        if (!self::tableReady()) {
            return ['items' => [], 'total' => 0];
        }
        $where = ['ID > 0'];
        $params = [];
        $q = trim((string)($opts['q'] ?? ''));
        if ($q !== '') {
            $where[] = 'NAME LIKE ?';
            $params[] = '%' . $q . '%';
        }
        $en = (string)($opts['enabled'] ?? '');
        if ($en === '1') {
            $where[] = 'ENABLED > 0';
        } elseif ($en === '0') {
            $where[] = 'ENABLED = 0';
        }
        $sqlWhere = implode(' AND ', $where);
        $pdo = Database::pdo();
        $stc = $pdo->prepare("SELECT COUNT(*) FROM base_promo_sources WHERE $sqlWhere");
        $stc->execute($params);
        $total = (int)$stc->fetchColumn();
        $st = $pdo->prepare(
            "SELECT ID AS id, NAME AS name, CONTACTS AS contacts, DESCRIPTION AS description,
                    RESULT AS result, DT_START AS dt_start, DT_END AS dt_end, DT_CREATE AS dt_create,
                    ENABLED AS enabled, SRC_ENABLED AS src_enabled
             FROM base_promo_sources WHERE $sqlWhere ORDER BY NAME ASC"
        );
        $st->execute($params);
        $items = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $items[] = self::cast($row);
        }
        return ['items' => $items, 'total' => $total];
    }

    public static function find(int $id): ?array
    {
        if ($id < 1 || !self::tableReady()) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, NAME AS name, CONTACTS AS contacts, DESCRIPTION AS description,
                    RESULT AS result, DT_START AS dt_start, DT_END AS dt_end, DT_CREATE AS dt_create,
                    ENABLED AS enabled, SRC_ENABLED AS src_enabled
             FROM base_promo_sources WHERE ID = ? LIMIT 1'
        );
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? self::cast($row) : null;
    }

    /** @param array<string,mixed> $post */
    public static function save(?int $id, array $post): int
    {
        if (!self::tableReady()) {
            throw new RuntimeException(t('acquisition.err.no_table'));
        }
        $name = trim((string)($post['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException(t('acquisition.sources.err.name'));
        }
        $data = [
            'name' => $name,
            'contacts' => trim((string)($post['contacts'] ?? '')),
            'description' => trim((string)($post['description'] ?? '')),
            'result' => trim((string)($post['result'] ?? '')),
            'dt_start' => self::parseDt($post['dt_start'] ?? ''),
            'dt_end' => self::parseDt($post['dt_end'] ?? ''),
            'enabled' => !empty($post['enabled']) ? 1 : 0,
            'src_enabled' => !empty($post['src_enabled']) ? 1 : 0,
        ];
        $pdo = Database::pdo();
        if ($id !== null && $id > 0) {
            $st = $pdo->prepare(
                'UPDATE base_promo_sources SET NAME=?, CONTACTS=?, DESCRIPTION=?, RESULT=?,
                 DT_START=?, DT_END=?, ENABLED=?, SRC_ENABLED=? WHERE ID=?'
            );
            $st->execute([
                $data['name'], $data['contacts'], $data['description'], $data['result'],
                $data['dt_start'], $data['dt_end'], $data['enabled'], $data['src_enabled'], $id,
            ]);
            return $id;
        }
        $st = $pdo->prepare(
            'INSERT INTO base_promo_sources
             (NAME, CONTACTS, DESCRIPTION, RESULT, DT_START, DT_END, DT_CREATE, ENABLED, SRC_ENABLED)
             VALUES (?,?,?,?,?,?,?,?,?)'
        );
        $st->execute([
            $data['name'], $data['contacts'], $data['description'], $data['result'],
            $data['dt_start'], $data['dt_end'], time(), $data['enabled'], $data['src_enabled'],
        ]);
        return (int)$pdo->lastInsertId();
    }

    public static function delete(int $id): void
    {
        if ($id < 1 || !self::tableReady()) {
            return;
        }
        Database::pdo()->prepare('DELETE FROM base_promo_sources WHERE ID = ?')->execute([$id]);
    }

    /** @param array<string,mixed> $row */
    private static function cast(array $row): array
    {
        foreach (['id', 'dt_start', 'dt_end', 'dt_create', 'enabled', 'src_enabled'] as $k) {
            if (array_key_exists($k, $row)) {
                $row[$k] = (int)$row[$k];
            }
        }
        foreach (['name', 'contacts', 'description', 'result'] as $k) {
            if (array_key_exists($k, $row)) {
                $row[$k] = (string)$row[$k];
            }
        }
        return $row;
    }

    /** @param mixed $raw */
    private static function parseDt($raw): int
    {
        if (is_numeric($raw) && (int)$raw > 0) {
            return (int)$raw;
        }
        $s = trim((string)$raw);
        if ($s === '') {
            return 0;
        }
        $ts = strtotime($s);
        return $ts !== false ? $ts : 0;
    }
}
