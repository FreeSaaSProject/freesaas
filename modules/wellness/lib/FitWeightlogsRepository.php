<?php
declare(strict_types=1);

final class FitWeightlogsRepository
{
    /** @return array<string,string> */
    public static function listSortMap(): array
    {
        return [
            'id' => 'ID',
            'dt' => 'DT',
            'weight' => 'WEIGHT',
        ];
    }

    /**
     * @param array{from?:int,to?:int,order?:string,limit?:int,offset?:int} $opts
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public static function list(array $opts = []): array
    {
        if (!Database::tableExists('base_fit_weightlogs')) {
            return ['items' => [], 'total' => 0];
        }
        $userId = WellnessOwner::userId();
        $pdo = Database::pdo();
        $where = ['ID_USER = ?'];
        $params = [$userId];
        $from = (int)($opts['from'] ?? 0);
        $to = (int)($opts['to'] ?? 0);
        if ($from > 0) {
            $where[] = 'DT >= ?';
            $params[] = $from;
        }
        if ($to > 0) {
            $where[] = 'DT <= ?';
            $params[] = $to;
        }
        $sqlWhere = implode(' AND ', $where);
        $stc = $pdo->prepare("SELECT COUNT(*) FROM base_fit_weightlogs WHERE $sqlWhere");
        $stc->execute($params);
        $total = (int)$stc->fetchColumn();

        $order = trim((string)($opts['order'] ?? ''));
        if ($order === '') {
            $order = 'DT DESC, ID DESC';
        }
        $limit = max(0, (int)($opts['limit'] ?? 50));
        $offset = max(0, (int)($opts['offset'] ?? 0));
        $sql = 'SELECT ID AS id, ID_USER AS user_id, DT_CREATE AS dt_create, DT AS dt,
                       WEIGHT AS weight, FAT AS fat, WATER AS water, MUSCLES AS muscles,
                       FATCLASS AS fatclass, METACALS AS metacals, METAAGE AS metaage,
                       INTFAT AS intfat, WAIST AS waist, HIPS AS hips
                FROM base_fit_weightlogs WHERE ' . $sqlWhere . ' ORDER BY ' . $order;
        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return ['items' => $st->fetchAll(PDO::FETCH_ASSOC) ?: [], 'total' => $total];
    }

    public static function findOwned(int $id): ?array
    {
        if ($id < 1 || !Database::tableExists('base_fit_weightlogs')) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, ID_USER AS user_id, DT_CREATE AS dt_create, DT AS dt,
                    WEIGHT AS weight, FAT AS fat, WATER AS water, MUSCLES AS muscles,
                    FATCLASS AS fatclass, METACALS AS metacals, METAAGE AS metaage,
                    INTFAT AS intfat, WAIST AS waist, HIPS AS hips
             FROM base_fit_weightlogs WHERE ID = ? AND ID_USER = ? LIMIT 1'
        );
        $st->execute([$id, WellnessOwner::userId()]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * @param array<string,mixed> $post
     * @return array<string,mixed>
     */
    public static function dataFromPost(array $post): array
    {
        $weight = max(0.0, (float)str_replace(',', '.', (string)($post['weight'] ?? 0)));
        if ($weight <= 0) {
            throw new InvalidArgumentException(t('wellness.err.weight_required'));
        }
        $dt = WellnessOwner::parseDt($post['dt'] ?? null);
        // Normalize to day start for unique (ID_USER, DT)
        $day = date('Y-m-d', $dt);
        $dtDay = strtotime($day . ' 00:00:00') ?: $dt;
        return [
            'dt' => (int)$dtDay,
            'weight' => $weight,
            'fat' => max(0.0, (float)str_replace(',', '.', (string)($post['fat'] ?? 0))),
            'water' => max(0.0, (float)str_replace(',', '.', (string)($post['water'] ?? 0))),
            'muscles' => max(0.0, (float)str_replace(',', '.', (string)($post['muscles'] ?? 0))),
            'fatclass' => max(0, min(255, (int)($post['fatclass'] ?? 0))),
            'metacals' => max(0, (int)($post['metacals'] ?? 0)),
            'metaage' => max(0, (int)($post['metaage'] ?? 0)),
            'intfat' => max(0.0, (float)str_replace(',', '.', (string)($post['intfat'] ?? 0))),
            'waist' => max(0, (int)($post['waist'] ?? 0)),
            'hips' => max(0, (int)($post['hips'] ?? 0)),
        ];
    }

    /** @param array<string,mixed> $data */
    public static function create(array $data): int
    {
        $userId = WellnessOwner::userId();
        // Upsert-like: replace same day
        $existing = Database::pdo()->prepare(
            'SELECT ID FROM base_fit_weightlogs WHERE ID_USER = ? AND DT = ? LIMIT 1'
        );
        $existing->execute([$userId, $data['dt']]);
        $exId = (int)$existing->fetchColumn();
        if ($exId > 0) {
            self::update($exId, $data);
            return $exId;
        }
        $st = Database::pdo()->prepare(
            'INSERT INTO base_fit_weightlogs
             (ID_USER, DT_CREATE, DT, WEIGHT, FAT, WATER, MUSCLES, FATCLASS, METACALS, METAAGE, INTFAT, WAIST, HIPS)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $st->execute([
            $userId,
            time(),
            $data['dt'],
            $data['weight'],
            $data['fat'],
            $data['water'],
            $data['muscles'],
            $data['fatclass'],
            $data['metacals'],
            $data['metaage'],
            $data['intfat'],
            $data['waist'],
            $data['hips'],
        ]);
        return (int)Database::pdo()->lastInsertId();
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data): void
    {
        if (!self::findOwned($id)) {
            throw new RuntimeException(t('wellness.err.forbidden'));
        }
        $st = Database::pdo()->prepare(
            'UPDATE base_fit_weightlogs
             SET DT = ?, WEIGHT = ?, FAT = ?, WATER = ?, MUSCLES = ?, FATCLASS = ?,
                 METACALS = ?, METAAGE = ?, INTFAT = ?, WAIST = ?, HIPS = ?
             WHERE ID = ? AND ID_USER = ?'
        );
        $st->execute([
            $data['dt'],
            $data['weight'],
            $data['fat'],
            $data['water'],
            $data['muscles'],
            $data['fatclass'],
            $data['metacals'],
            $data['metaage'],
            $data['intfat'],
            $data['waist'],
            $data['hips'],
            $id,
            WellnessOwner::userId(),
        ]);
    }

    /** @param list<int> $ids @return int */
    public static function deleteByIds(array $ids): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $i): bool => $i > 0)));
        if ($ids === [] || !Database::tableExists('base_fit_weightlogs')) {
            return 0;
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $params = $ids;
        $params[] = WellnessOwner::userId();
        $st = Database::pdo()->prepare("DELETE FROM base_fit_weightlogs WHERE ID IN ($ph) AND ID_USER = ?");
        $st->execute($params);
        return $st->rowCount();
    }
}
