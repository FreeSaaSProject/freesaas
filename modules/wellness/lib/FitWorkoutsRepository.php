<?php
declare(strict_types=1);

final class FitWorkoutsRepository
{
    /** @return array<string,string> */
    public static function listSortMap(): array
    {
        return [
            'id' => 'ID',
            'dt_start' => 'DT_START',
            'ccals' => 'CCALS',
            'name' => 'NAME',
        ];
    }

    /**
     * @param array{q?:string,date?:string,from?:int,to?:int,order?:string,limit?:int,offset?:int} $opts
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public static function list(array $opts = []): array
    {
        if (!Database::tableExists('base_fitlogs')) {
            return ['items' => [], 'total' => 0];
        }
        $userId = WellnessOwner::userId();
        $pdo = Database::pdo();
        $where = ['ID_USER = ?'];
        $params = [$userId];

        $date = trim((string)($opts['date'] ?? ''));
        if ($date !== '') {
            [$from, $to] = WellnessOwner::dayRange($date);
            $where[] = 'DT_START BETWEEN ? AND ?';
            $params[] = $from;
            $params[] = $to;
        } else {
            $from = (int)($opts['from'] ?? 0);
            $to = (int)($opts['to'] ?? 0);
            if ($from > 0) {
                $where[] = 'DT_START >= ?';
                $params[] = $from;
            }
            if ($to > 0) {
                $where[] = 'DT_START <= ?';
                $params[] = $to;
            }
        }

        $q = trim((string)($opts['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(NAME LIKE ? OR CAST(ID AS CHAR) = ?)';
            $params[] = '%' . $q . '%';
            $params[] = $q;
        }

        $sqlWhere = implode(' AND ', $where);
        $stc = $pdo->prepare("SELECT COUNT(*) FROM base_fitlogs WHERE $sqlWhere");
        $stc->execute($params);
        $total = (int)$stc->fetchColumn();

        $order = trim((string)($opts['order'] ?? ''));
        if ($order === '') {
            $order = 'DT_START DESC, ID DESC';
        }
        $limit = max(0, (int)($opts['limit'] ?? 50));
        $offset = max(0, (int)($opts['offset'] ?? 0));
        $sql = "SELECT ID AS id, ID_USER AS user_id, DT_CREATE AS dt_create,
                       DT_START AS dt_start, DT_END AS dt_end, NAME AS name, CCALS AS ccals
                FROM base_fitlogs WHERE $sqlWhere ORDER BY $order";
        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return ['items' => $st->fetchAll(PDO::FETCH_ASSOC) ?: [], 'total' => $total];
    }

    public static function findOwned(int $id): ?array
    {
        if ($id < 1 || !Database::tableExists('base_fitlogs')) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, ID_USER AS user_id, DT_CREATE AS dt_create,
                    DT_START AS dt_start, DT_END AS dt_end, NAME AS name, CCALS AS ccals
             FROM base_fitlogs WHERE ID = ? AND ID_USER = ? LIMIT 1'
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
        if ($name === '') {
            throw new InvalidArgumentException(t('wellness.err.name_required'));
        }
        $dtStart = WellnessOwner::parseDt($post['dt_start'] ?? $post['dt'] ?? null);
        $dtEndRaw = $post['dt_end'] ?? null;
        $dtEnd = ($dtEndRaw !== null && trim((string)$dtEndRaw) !== '')
            ? WellnessOwner::parseDt($dtEndRaw)
            : $dtStart;
        if ($dtEnd < $dtStart) {
            $dtEnd = $dtStart;
        }
        return [
            'name' => mb_substr($name, 0, 255),
            'dt_start' => $dtStart,
            'dt_end' => $dtEnd,
            'ccals' => max(0, (int)($post['ccals'] ?? 0)),
        ];
    }

    /** @param array<string,mixed> $data */
    public static function create(array $data): int
    {
        $st = Database::pdo()->prepare(
            'INSERT INTO base_fitlogs (ID_USER, DT_CREATE, DT_START, DT_END, NAME, CCALS)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $st->execute([
            WellnessOwner::userId(),
            time(),
            $data['dt_start'],
            $data['dt_end'],
            $data['name'],
            $data['ccals'],
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
            'UPDATE base_fitlogs SET DT_START = ?, DT_END = ?, NAME = ?, CCALS = ?
             WHERE ID = ? AND ID_USER = ?'
        );
        $st->execute([
            $data['dt_start'],
            $data['dt_end'],
            $data['name'],
            $data['ccals'],
            $id,
            WellnessOwner::userId(),
        ]);
    }

    /** @param list<int> $ids @return int */
    public static function deleteByIds(array $ids): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $i): bool => $i > 0)));
        if ($ids === [] || !Database::tableExists('base_fitlogs')) {
            return 0;
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $params = $ids;
        $params[] = WellnessOwner::userId();
        $st = Database::pdo()->prepare("DELETE FROM base_fitlogs WHERE ID IN ($ph) AND ID_USER = ?");
        $st->execute($params);
        return $st->rowCount();
    }
}
