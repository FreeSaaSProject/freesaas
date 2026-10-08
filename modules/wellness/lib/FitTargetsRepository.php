<?php
declare(strict_types=1);

final class FitTargetsRepository
{
    /** @return array<string,string> */
    public static function listSortMap(): array
    {
        return [
            'id' => 'ID',
            'dt' => 'DT',
            'ccals' => 'CCALS',
            'name' => 'NAME',
        ];
    }

    /**
     * @param array{order?:string,limit?:int,offset?:int} $opts
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public static function list(array $opts = []): array
    {
        if (!Database::tableExists('base_fit_targets')) {
            return ['items' => [], 'total' => 0];
        }
        $userId = WellnessOwner::userId();
        $pdo = Database::pdo();
        $where = ['ID_USER = ?'];
        $params = [$userId];
        $sqlWhere = implode(' AND ', $where);
        $stc = $pdo->prepare("SELECT COUNT(*) FROM base_fit_targets WHERE $sqlWhere");
        $stc->execute($params);
        $total = (int)$stc->fetchColumn();

        $order = trim((string)($opts['order'] ?? ''));
        if ($order === '') {
            $order = 'DT DESC, ID DESC';
        }
        $limit = max(0, (int)($opts['limit'] ?? 50));
        $offset = max(0, (int)($opts['offset'] ?? 0));
        $sql = 'SELECT ID AS id, ID_USER AS user_id, DT_CREATE AS dt_create, DT AS dt,
                       WEIGHT AS weight, CCALS AS ccals, CARBS AS carbs, PROTEINS AS proteins,
                       FATS AS fats, NAME AS name
                FROM base_fit_targets WHERE ' . $sqlWhere . ' ORDER BY ' . $order;
        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return ['items' => $st->fetchAll(PDO::FETCH_ASSOC) ?: [], 'total' => $total];
    }

    /** Latest target with DT <= $asOf (unix). */
    public static function activeAt(int $asOf): ?array
    {
        if (!Database::tableExists('base_fit_targets')) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, ID_USER AS user_id, DT_CREATE AS dt_create, DT AS dt,
                    WEIGHT AS weight, CCALS AS ccals, CARBS AS carbs, PROTEINS AS proteins,
                    FATS AS fats, NAME AS name
             FROM base_fit_targets
             WHERE ID_USER = ? AND DT <= ?
             ORDER BY DT DESC, ID DESC LIMIT 1'
        );
        $st->execute([WellnessOwner::userId(), $asOf]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function findOwned(int $id): ?array
    {
        if ($id < 1 || !Database::tableExists('base_fit_targets')) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, ID_USER AS user_id, DT_CREATE AS dt_create, DT AS dt,
                    WEIGHT AS weight, CCALS AS ccals, CARBS AS carbs, PROTEINS AS proteins,
                    FATS AS fats, NAME AS name
             FROM base_fit_targets WHERE ID = ? AND ID_USER = ? LIMIT 1'
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
        $name = trim((string)($post['name'] ?? ''));
        return [
            'name' => mb_substr($name !== '' ? $name : t('wellness.targets.default_name'), 0, 255),
            'dt' => WellnessOwner::parseDt($post['dt'] ?? null),
            'weight' => max(0.0, (float)str_replace(',', '.', (string)($post['weight'] ?? 0))),
            'ccals' => max(0, (int)($post['ccals'] ?? 0)),
            'carbs' => max(0.0, (float)str_replace(',', '.', (string)($post['carbs'] ?? 0))),
            'proteins' => max(0.0, (float)str_replace(',', '.', (string)($post['proteins'] ?? 0))),
            'fats' => max(0.0, (float)str_replace(',', '.', (string)($post['fats'] ?? 0))),
        ];
    }

    /** @param array<string,mixed> $data */
    public static function create(array $data): int
    {
        $st = Database::pdo()->prepare(
            'INSERT INTO base_fit_targets
             (ID_USER, DT_CREATE, DT, WEIGHT, CCALS, CARBS, PROTEINS, FATS, NAME)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $st->execute([
            WellnessOwner::userId(),
            time(),
            $data['dt'],
            $data['weight'],
            $data['ccals'],
            $data['carbs'],
            $data['proteins'],
            $data['fats'],
            $data['name'],
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
            'UPDATE base_fit_targets
             SET DT = ?, WEIGHT = ?, CCALS = ?, CARBS = ?, PROTEINS = ?, FATS = ?, NAME = ?
             WHERE ID = ? AND ID_USER = ?'
        );
        $st->execute([
            $data['dt'],
            $data['weight'],
            $data['ccals'],
            $data['carbs'],
            $data['proteins'],
            $data['fats'],
            $data['name'],
            $id,
            WellnessOwner::userId(),
        ]);
    }

    /** @param list<int> $ids @return int */
    public static function deleteByIds(array $ids): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $i): bool => $i > 0)));
        if ($ids === [] || !Database::tableExists('base_fit_targets')) {
            return 0;
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $params = $ids;
        $params[] = WellnessOwner::userId();
        $st = Database::pdo()->prepare("DELETE FROM base_fit_targets WHERE ID IN ($ph) AND ID_USER = ?");
        $st->execute($params);
        return $st->rowCount();
    }
}
