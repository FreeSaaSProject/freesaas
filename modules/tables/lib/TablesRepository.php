<?php
declare(strict_types=1);

final class TablesRepository
{
    /** @var array<string,array{plain:array<int,string>,html:array<int,string>}> request memo */
    private static $labelMapsCache = [];

    /** @return array<string,string> */
    public static function listSortMap(): array
    {
        return [
            'id' => 'ID',
            'name' => 'NAME',
            'enabled' => 'ENABLED',
        ];
    }

    public static function isActive(): bool
    {
        return ModuleManager::isEnabled('tables') && Database::tableExists('base_tables');
    }

    /**
     * When tables module is off, station id defaults to 1.
     */
    public static function normalizeId(int $idTable): int
    {
        if (!self::isActive()) {
            return 1;
        }
        return $idTable > 0 ? $idTable : 1;
    }

    /**
     * @param array{q?:string,enabled?:string|null,order?:string,limit?:int,offset?:int} $opts
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public static function list(array $opts = []): array
    {
        if (!Database::tableExists('base_tables')) {
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
        if (array_key_exists('enabled', $opts) && $opts['enabled'] !== null && $opts['enabled'] !== '') {
            $where[] = 'ENABLED = ?';
            $params[] = (int)$opts['enabled'];
        }
        $sqlWhere = implode(' AND ', $where);
        $stc = $pdo->prepare("SELECT COUNT(*) FROM base_tables WHERE $sqlWhere");
        $stc->execute($params);
        $total = (int)$stc->fetchColumn();
        $order = trim((string)($opts['order'] ?? '')) ?: 'NAME ASC';
        $limit = max(0, (int)($opts['limit'] ?? 50));
        $offset = max(0, (int)($opts['offset'] ?? 0));
        $sql = "SELECT ID AS id, DT_CREATE AS dt_create, ID_OPERATOR_CREATE AS id_operator_create,
                       PROC_TYPES AS proc_types, PROCS AS procs, NAME AS name,
                       ENABLED AS enabled, GENDER AS gender
                FROM base_tables WHERE $sqlWhere ORDER BY $order";
        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return ['items' => $st->fetchAll(PDO::FETCH_ASSOC) ?: [], 'total' => $total];
    }

    /**
     * Legacy names often store HTML like о&lt;SUB&gt;1&lt;/SUB&gt; — options cannot render tags.
     */
    public static function plainLabel(string $name): string
    {
        $name = html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $name = strip_tags($name);
        $name = preg_replace('/\s+/u', ' ', $name) ?? $name;
        return trim($name);
    }

    /** Safe HTML for labels that keep &lt;sub&gt;/&lt;sup&gt; (not for &lt;option&gt; text). */
    public static function htmlLabel(string $name): string
    {
        $name = html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $name = preg_replace('#<(script|iframe|object|embed|form)[^>]*>.*?</\1>#is', '', $name) ?? $name;
        $name = preg_replace('#</?(script|iframe|object|embed|form)[^>]*>#i', '', $name) ?? $name;
        return strip_tags($name, '<sub><sup><b><strong><i><em>');
    }

    /**
     * One query per request: plain labels (options) + safe HTML (sub/sup for chips).
     *
     * @return array{plain:array<int,string>,html:array<int,string>}
     */
    public static function labelMaps(bool $enabledOnly = true): array
    {
        $key = $enabledOnly ? '1' : '0';
        if (isset(self::$labelMapsCache[$key])) {
            return self::$labelMapsCache[$key];
        }
        $empty = ['plain' => [], 'html' => []];
        if (!Database::tableExists('base_tables')) {
            return self::$labelMapsCache[$key] = $empty;
        }
        $sql = 'SELECT ID, NAME FROM base_tables';
        if ($enabledOnly) {
            $sql .= ' WHERE ENABLED > 0';
        }
        $sql .= ' ORDER BY NAME ASC';
        $plain = [];
        $html = [];
        foreach (Database::pdo()->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $id = (int)$r['ID'];
            $raw = (string)$r['NAME'];
            $p = self::plainLabel($raw);
            $plain[$id] = $p !== '' ? $p : ('#' . $id);
            $h = self::htmlLabel($raw);
            $html[$id] = $h !== '' ? $h : e($plain[$id]);
        }
        return self::$labelMapsCache[$key] = ['plain' => $plain, 'html' => $html];
    }

    /** @return array<int,string> plain labels (safe for &lt;option&gt;) */
    public static function names(bool $enabledOnly = true): array
    {
        return self::labelMaps($enabledOnly)['plain'];
    }

    public static function find(int $id): ?array
    {
        if ($id < 1 || !Database::tableExists('base_tables')) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, DT_CREATE AS dt_create, ID_OPERATOR_CREATE AS id_operator_create,
                    PROC_TYPES AS proc_types, PROCS AS procs, NAME AS name,
                    ENABLED AS enabled, GENDER AS gender
             FROM base_tables WHERE ID = ? LIMIT 1'
        );
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $row['proc_type_ids'] = self::parseIds((string)$row['proc_types']);
        $row['gender_ids'] = self::parseIds((string)$row['gender']);
        return $row;
    }

    /** @return list<int> */
    public static function parseIds(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '' || $raw === '0') {
            return [];
        }
        $parts = preg_split('/[#,\s]+/', $raw) ?: [];
        $out = [];
        foreach ($parts as $p) {
            $n = (int)$p;
            if ($n > 0) {
                $out[] = $n;
            }
        }
        return array_values(array_unique($out));
    }

    /** @param list<int|string> $ids */
    public static function joinIds(array $ids): string
    {
        $clean = [];
        foreach ($ids as $id) {
            $n = (int)$id;
            if ($n > 0) {
                $clean[] = $n;
            }
        }
        $clean = array_values(array_unique($clean));
        return $clean === [] ? '0' : implode('#', $clean);
    }

    /** @param array<string,mixed> $post */
    public static function dataFromPost(array $post): array
    {
        $name = trim((string)($post['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException(t('tables.err.name_required'));
        }
        $procTypes = $post['proc_types'] ?? [];
        if (!is_array($procTypes)) {
            $procTypes = [];
        }
        $genders = $post['gender'] ?? [];
        if (!is_array($genders)) {
            $genders = [];
        }
        return [
            'name' => mb_substr($name, 0, 255),
            'enabled' => !empty($post['enabled']) ? 1 : 0,
            'proc_types' => self::joinIds($procTypes),
            'gender' => self::joinIds($genders),
            'procs' => '0',
        ];
    }

    /** @param array<string,mixed> $data */
    public static function create(array $data, int $userId): int
    {
        $st = Database::pdo()->prepare(
            'INSERT INTO base_tables
             (DT_CREATE, ID_OPERATOR_CREATE, PROC_TYPES, PROCS, NAME, ENABLED, GENDER)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $st->execute([
            time(),
            $userId,
            $data['proc_types'],
            $data['procs'],
            $data['name'],
            $data['enabled'],
            $data['gender'],
        ]);
        return (int)Database::pdo()->lastInsertId();
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data): void
    {
        Database::pdo()->prepare(
            'UPDATE base_tables SET PROC_TYPES = ?, PROCS = ?, NAME = ?, ENABLED = ?, GENDER = ? WHERE ID = ?'
        )->execute([
            $data['proc_types'],
            $data['procs'],
            $data['name'],
            $data['enabled'],
            $data['gender'],
            $id,
        ]);
    }

    /** @param list<int> $ids */
    public static function deleteByIds(array $ids): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === []) {
            return 0;
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = Database::pdo()->prepare("DELETE FROM base_tables WHERE ID IN ($ph)");
        $st->execute($ids);
        return $st->rowCount();
    }
}
