<?php
declare(strict_types=1);

final class RecordsTypesRepository
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
        if (!Database::tableExists('base_records_types')) {
            return ['items' => [], 'total' => 0];
        }
        $pdo = Database::pdo();
        $where = ['1=1'];
        $params = [];
        $q = trim((string)($opts['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(NAME LIKE ? OR DESCR LIKE ? OR CAST(ID AS CHAR) = ?)';
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $q;
        }
        $sqlWhere = implode(' AND ', $where);
        $stc = $pdo->prepare("SELECT COUNT(*) FROM base_records_types WHERE $sqlWhere");
        $stc->execute($params);
        $total = (int)$stc->fetchColumn();
        $order = trim((string)($opts['order'] ?? '')) ?: 'ID ASC';
        $limit = max(0, (int)($opts['limit'] ?? 50));
        $offset = max(0, (int)($opts['offset'] ?? 0));
        $sql = "SELECT ID AS id, DT_CREATE AS dt_create, NAME AS name, DESCR AS descr,
                       COLOR AS color, COLOR_BACKGROUND AS color_background,
                       ENABLED AS enabled, KPI_ENABLED AS kpi_enabled, IS_HIDDEN AS is_hidden
                FROM base_records_types WHERE $sqlWhere ORDER BY $order";
        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return ['items' => $st->fetchAll(PDO::FETCH_ASSOC) ?: [], 'total' => $total];
    }

    public static function find(int $id): ?array
    {
        if ($id < 1 || !Database::tableExists('base_records_types')) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, DT_CREATE AS dt_create, NAME AS name, DESCR AS descr,
                    COLOR AS color, COLOR_BACKGROUND AS color_background,
                    ENABLED AS enabled, KPI_ENABLED AS kpi_enabled,
                    SCORE_VALUE AS score_value, SCORE_TYPE_ID AS score_type_id,
                    SCORE_DIVERSITY AS score_diversity, SCORE_INSTANCES_MIN AS score_instances_min,
                    SCORE_INSTANCES_MAX AS score_instances_max, VOLUME AS volume,
                    STRINGS_AMOUNT AS strings_amount, `INTERSECT` AS intersect_flag,
                    IS_HIDDEN AS is_hidden, ID_POSITION AS id_position
             FROM base_records_types WHERE ID = ? LIMIT 1'
        );
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** @return array<int,string> */
    public static function options(): array
    {
        $out = [];
        if (!Database::tableExists('base_records_types')) {
            return $out;
        }
        $rows = Database::pdo()->query(
            'SELECT ID, NAME FROM base_records_types WHERE ENABLED = 1 ORDER BY NAME ASC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $r) {
            $out[(int)$r['ID']] = (string)$r['NAME'];
        }
        return $out;
    }

    /** @param array<string,mixed> $post @return array<string,mixed> */
    public static function dataFromPost(array $post): array
    {
        $name = trim((string)($post['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException(t('audio_dicts.err.name_required'));
        }
        return [
            'name' => mb_substr($name, 0, 20),
            'descr' => mb_substr(trim((string)($post['descr'] ?? '')), 0, 255),
            'color' => mb_substr(trim((string)($post['color'] ?? '')), 0, 10),
            'color_background' => mb_substr(trim((string)($post['color_background'] ?? '')), 0, 10),
            'enabled' => !empty($post['enabled']) ? 1 : 0,
            'kpi_enabled' => !empty($post['kpi_enabled']) ? 1 : 0,
            'is_hidden' => !empty($post['is_hidden']) ? 1 : 0,
            'score_value' => (int)($post['score_value'] ?? 0),
            'score_diversity' => max(0, (int)($post['score_diversity'] ?? 0)),
            'score_instances_min' => (int)($post['score_instances_min'] ?? 0),
            'score_instances_max' => (int)($post['score_instances_max'] ?? 0),
            'volume' => max(0, (int)($post['volume'] ?? 0)),
            'strings_amount' => max(0, (int)($post['strings_amount'] ?? 0)),
            'intersect_flag' => !empty($post['intersect_flag']) ? 1 : 0,
            'id_position' => trim((string)($post['id_position'] ?? '')),
        ];
    }

    /** @param array<string,mixed> $data */
    public static function create(array $data): int
    {
        $st = Database::pdo()->prepare(
            'INSERT INTO base_records_types
             (DT_CREATE, NAME, DESCR, COLOR, COLOR_BACKGROUND, ENABLED, KPI_ENABLED,
              SCORE_VALUE, SCORE_DIVERSITY, SCORE_INSTANCES_MIN, SCORE_INSTANCES_MAX,
              VOLUME, STRINGS_AMOUNT, `INTERSECT`, IS_HIDDEN, ID_POSITION)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $st->execute([
            time(), $data['name'], $data['descr'], $data['color'], $data['color_background'],
            $data['enabled'], $data['kpi_enabled'], $data['score_value'], $data['score_diversity'],
            $data['score_instances_min'], $data['score_instances_max'], $data['volume'],
            $data['strings_amount'], $data['intersect_flag'], $data['is_hidden'], $data['id_position'],
        ]);
        EventBus::emit('audio_dicts.changed', ['entity' => 'types']);
        return (int)Database::pdo()->lastInsertId();
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data): void
    {
        $st = Database::pdo()->prepare(
            'UPDATE base_records_types SET
             NAME=?, DESCR=?, COLOR=?, COLOR_BACKGROUND=?, ENABLED=?, KPI_ENABLED=?,
             SCORE_VALUE=?, SCORE_DIVERSITY=?, SCORE_INSTANCES_MIN=?, SCORE_INSTANCES_MAX=?,
             VOLUME=?, STRINGS_AMOUNT=?, `INTERSECT`=?, IS_HIDDEN=?, ID_POSITION=?
             WHERE ID=?'
        );
        $st->execute([
            $data['name'], $data['descr'], $data['color'], $data['color_background'],
            $data['enabled'], $data['kpi_enabled'], $data['score_value'], $data['score_diversity'],
            $data['score_instances_min'], $data['score_instances_max'], $data['volume'],
            $data['strings_amount'], $data['intersect_flag'], $data['is_hidden'], $data['id_position'],
            $id,
        ]);
        EventBus::emit('audio_dicts.changed', ['entity' => 'types', 'id' => $id]);
    }

    /** @param list<int> $ids */
    public static function deleteByIds(array $ids): int
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn ($i) => $i > 0));
        if (!$ids) {
            return 0;
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = Database::pdo()->prepare("DELETE FROM base_records_types WHERE ID IN ($in)");
        $st->execute($ids);
        EventBus::emit('audio_dicts.changed', ['entity' => 'types']);
        return $st->rowCount();
    }
}
