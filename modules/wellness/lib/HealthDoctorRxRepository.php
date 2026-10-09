<?php
declare(strict_types=1);

final class HealthDoctorRxRepository
{
    /** @return array<string,string> */
    public static function listSortMap(): array
    {
        return ['id' => 'ID', 'dt' => 'DT', 'doctor' => 'DOCTOR'];
    }

    /**
     * @param array{q?:string,date?:string,from?:int,to?:int,order?:string,limit?:int,offset?:int} $opts
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public static function list(array $opts = []): array
    {
        if (!Database::tableExists('base_healths_recipes')) {
            return ['items' => [], 'total' => 0];
        }
        $userId = WellnessOwner::userId();
        $pdo = Database::pdo();
        $where = ['ID_USER = ?'];
        $params = [$userId];

        $date = trim((string)($opts['date'] ?? ''));
        if ($date !== '') {
            [$from, $to] = WellnessOwner::dayRange($date);
            $where[] = 'DT BETWEEN ? AND ?';
            $params[] = $from;
            $params[] = $to;
        } else {
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
        }

        $q = trim((string)($opts['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(DOCTOR LIKE ? OR DATA LIKE ? OR CAST(ID AS CHAR) = ?)';
            $params[] = '%' . $q . '%';
            $params[] = '%' . $q . '%';
            $params[] = $q;
        }

        $sqlWhere = implode(' AND ', $where);
        $stc = $pdo->prepare("SELECT COUNT(*) FROM base_healths_recipes WHERE $sqlWhere");
        $stc->execute($params);
        $total = (int)$stc->fetchColumn();

        $order = trim((string)($opts['order'] ?? '')) ?: 'DT DESC, ID DESC';
        $limit = max(0, (int)($opts['limit'] ?? 50));
        $offset = max(0, (int)($opts['offset'] ?? 0));
        $sql = "SELECT ID AS id, ID_USER AS user_id, DT_CREATE AS dt_create, DT AS dt,
                       DOCTOR AS doctor, DATA AS data
                FROM base_healths_recipes WHERE $sqlWhere ORDER BY $order";
        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return ['items' => $st->fetchAll(PDO::FETCH_ASSOC) ?: [], 'total' => $total];
    }

    public static function findOwned(int $id): ?array
    {
        if ($id < 1 || !Database::tableExists('base_healths_recipes')) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, ID_USER AS user_id, DT_CREATE AS dt_create, DT AS dt,
                    DOCTOR AS doctor, DATA AS data
             FROM base_healths_recipes WHERE ID = ? AND ID_USER = ? LIMIT 1'
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
        $doctor = trim((string)($post['doctor'] ?? ''));
        $data = trim((string)($post['data'] ?? ''));
        if ($data === '') {
            throw new InvalidArgumentException(t('wellness.err.rx_data_required'));
        }
        return [
            'dt' => WellnessOwner::parseDt($post['dt'] ?? null),
            'doctor' => mb_substr($doctor, 0, 64),
            'data' => $data,
        ];
    }

    /** @param array<string,mixed> $data */
    public static function create(array $data): int
    {
        return WellnessOwner::insertOwned('base_healths_recipes', [
            'DT_CREATE' => time(),
            'DT' => (int)$data['dt'],
            'DOCTOR' => $data['doctor'],
            'DATA' => $data['data'],
        ]);
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data): void
    {
        if (!self::findOwned($id)) {
            throw new RuntimeException(t('wellness.err.forbidden'));
        }
        $st = Database::pdo()->prepare(
            'UPDATE base_healths_recipes SET DT = ?, DOCTOR = ?, DATA = ?
             WHERE ID = ? AND ID_USER = ?'
        );
        $st->execute([
            $data['dt'],
            $data['doctor'],
            $data['data'],
            $id,
            WellnessOwner::userId(),
        ]);
    }

    /** @param list<int> $ids @return int */
    public static function deleteByIds(array $ids): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $i): bool => $i > 0)));
        if ($ids === [] || !Database::tableExists('base_healths_recipes')) {
            return 0;
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $params = $ids;
        $params[] = WellnessOwner::userId();
        $st = Database::pdo()->prepare("DELETE FROM base_healths_recipes WHERE ID IN ($ph) AND ID_USER = ?");
        $st->execute($params);
        return $st->rowCount();
    }
}
