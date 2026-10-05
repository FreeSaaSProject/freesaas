<?php
declare(strict_types=1);

final class RecordsTrashesRepository
{
    /** @return array<string,string> */
    public static function listSortMap(): array
    {
        return ['id' => 'ID', 'name' => 'NAME', 'enabled' => 'ENABLED'];
    }

    /**
     * @param array{q?:string,order?:string,limit?:int,offset?:int} $opts
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public static function list(array $opts = []): array
    {
        if (!Database::tableExists('base_records_trashes')) {
            return ['items' => [], 'total' => 0];
        }
        $pdo = Database::pdo();
        $where = ['1=1'];
        $params = [];
        $q = trim((string)($opts['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(NAME LIKE ? OR CAST(ID AS CHAR) = ?)';
            $params[] = '%' . $q . '%';
            $params[] = $q;
        }
        $sqlWhere = implode(' AND ', $where);
        $stc = $pdo->prepare("SELECT COUNT(*) FROM base_records_trashes WHERE $sqlWhere");
        $stc->execute($params);
        $total = (int)$stc->fetchColumn();
        $order = trim((string)($opts['order'] ?? '')) ?: 'ID DESC';
        $limit = max(0, (int)($opts['limit'] ?? 50));
        $offset = max(0, (int)($opts['offset'] ?? 0));
        $sql = "SELECT ID AS id, DT_CREATE AS dt_create, NAME AS name, ENABLED AS enabled
                FROM base_records_trashes WHERE $sqlWhere ORDER BY $order";
        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return ['items' => $st->fetchAll(PDO::FETCH_ASSOC) ?: [], 'total' => $total];
    }

    public static function find(int $id): ?array
    {
        if ($id < 1 || !Database::tableExists('base_records_trashes')) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, DT_CREATE AS dt_create, NAME AS name, ENABLED AS enabled
             FROM base_records_trashes WHERE ID = ? LIMIT 1'
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
            'enabled' => !empty($post['enabled']) ? 1 : 0,
        ];
    }

    /** @param array<string,mixed> $data */
    public static function create(array $data): int
    {
        $st = Database::pdo()->prepare(
            'INSERT INTO base_records_trashes (DT_CREATE, NAME, ENABLED) VALUES (?, ?, ?)'
        );
        $st->execute([time(), $data['name'], $data['enabled']]);
        EventBus::emit('audio_dicts.changed', ['entity' => 'trashes']);
        return (int)Database::pdo()->lastInsertId();
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data): void
    {
        $st = Database::pdo()->prepare(
            'UPDATE base_records_trashes SET NAME = ?, ENABLED = ? WHERE ID = ?'
        );
        $st->execute([$data['name'], $data['enabled'], $id]);
        EventBus::emit('audio_dicts.changed', ['entity' => 'trashes', 'id' => $id]);
    }

    public static function findByName(string $name): ?array
    {
        $name = trim($name);
        if ($name === '' || !Database::tableExists('base_records_trashes')) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, DT_CREATE AS dt_create, NAME AS name, ENABLED AS enabled
             FROM base_records_trashes WHERE NAME = ? LIMIT 1'
        );
        $st->execute([mb_substr($name, 0, 255)]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** Insert if missing; returns id. */
    public static function ensure(string $name, bool $enabled = true): int
    {
        $existing = self::findByName($name);
        if ($existing) {
            return (int)$existing['id'];
        }
        return self::create([
            'name' => mb_substr(trim($name), 0, 255),
            'enabled' => $enabled ? 1 : 0,
        ]);
    }

    /** Insert many trash phrases; skips names already present. Returns count created. */
    public static function ensureMany(array $names): int
    {
        $clean = [];
        foreach ($names as $name) {
            $name = mb_substr(trim((string)$name), 0, 255);
            if ($name !== '') {
                $clean[$name] = $name;
            }
        }
        if (!$clean || !Database::tableExists('base_records_trashes')) {
            return 0;
        }
        $pdo = Database::pdo();
        $existing = [];
        $list = array_values($clean);
        // Chunk IN queries
        foreach (array_chunk($list, 100) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $st = $pdo->prepare("SELECT NAME FROM base_records_trashes WHERE NAME IN ($in)");
            $st->execute($chunk);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $n) {
                $existing[(string)$n] = true;
            }
        }
        $created = 0;
        $now = time();
        $ins = $pdo->prepare('INSERT INTO base_records_trashes (DT_CREATE, NAME, ENABLED) VALUES (?, ?, 1)');
        foreach ($clean as $name) {
            if (isset($existing[$name])) {
                continue;
            }
            $ins->execute([$now, $name]);
            $created++;
        }
        if ($created > 0) {
            EventBus::emit('audio_dicts.changed', ['entity' => 'trashes']);
        }
        return $created;
    }

    /** @param list<int> $ids */
    public static function deleteByIds(array $ids): int
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn ($i) => $i > 0));
        if (!$ids) {
            return 0;
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = Database::pdo()->prepare("DELETE FROM base_records_trashes WHERE ID IN ($in)");
        $st->execute($ids);
        EventBus::emit('audio_dicts.changed', ['entity' => 'trashes']);
        return $st->rowCount();
    }
}
