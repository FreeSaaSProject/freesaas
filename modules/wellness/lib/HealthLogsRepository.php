<?php
declare(strict_types=1);

final class HealthLogsRepository
{
    /** @return array<string,string> */
    public static function listSortMap(): array
    {
        return ['id' => 'l.ID', 'dt' => 'l.DT', 'level' => 'l.LEVEL'];
    }

    /**
     * @param array{q?:string,date?:string,from?:int,to?:int,order?:string,limit?:int,offset?:int} $opts
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public static function list(array $opts = []): array
    {
        if (!Database::tableExists('base_healths_logs')) {
            return ['items' => [], 'total' => 0];
        }
        $userId = WellnessOwner::userId();
        $pdo = Database::pdo();
        $where = ['l.ID_USER = ?'];
        $params = [$userId];

        $date = trim((string)($opts['date'] ?? ''));
        if ($date !== '') {
            [$from, $to] = WellnessOwner::dayRange($date);
            $where[] = 'l.DT BETWEEN ? AND ?';
            $params[] = $from;
            $params[] = $to;
        } else {
            $from = (int)($opts['from'] ?? 0);
            $to = (int)($opts['to'] ?? 0);
            if ($from > 0) {
                $where[] = 'l.DT >= ?';
                $params[] = $from;
            }
            if ($to > 0) {
                $where[] = 'l.DT <= ?';
                $params[] = $to;
            }
        }

        $q = trim((string)($opts['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(l.DESCR LIKE ? OR s.NAME LIKE ? OR m.NAME LIKE ? OR CAST(l.ID AS CHAR) = ?)';
            $params[] = '%' . $q . '%';
            $params[] = '%' . $q . '%';
            $params[] = '%' . $q . '%';
            $params[] = $q;
        }

        $sqlWhere = implode(' AND ', $where);
        $join = 'FROM base_healths_logs l
                 LEFT JOIN base_healths_symptoms s ON s.ID = l.ID_SYMPTOM AND s.ID_USER = l.ID_USER
                 LEFT JOIN base_healths_medicines m ON m.ID = l.ID_MEDICINE AND m.ID_USER = l.ID_USER';

        $stc = $pdo->prepare("SELECT COUNT(*) $join WHERE $sqlWhere");
        $stc->execute($params);
        $total = (int)$stc->fetchColumn();

        $order = trim((string)($opts['order'] ?? '')) ?: 'l.DT DESC, l.ID DESC';
        $limit = max(0, (int)($opts['limit'] ?? 50));
        $offset = max(0, (int)($opts['offset'] ?? 0));
        $sql = "SELECT l.ID AS id, l.ID_USER AS user_id, l.DT_CREATE AS dt_create, l.DT AS dt,
                       l.ID_SYMPTOM AS symptom_id, l.ID_MEDICINE AS medicine_id,
                       l.LEVEL AS level, l.DESCR AS descr,
                       COALESCE(s.NAME, '') AS symptom_name,
                       COALESCE(m.NAME, '') AS medicine_name
                $join WHERE $sqlWhere ORDER BY $order";
        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return ['items' => $st->fetchAll(PDO::FETCH_ASSOC) ?: [], 'total' => $total];
    }

    public static function findOwned(int $id): ?array
    {
        if ($id < 1 || !Database::tableExists('base_healths_logs')) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT l.ID AS id, l.ID_USER AS user_id, l.DT_CREATE AS dt_create, l.DT AS dt,
                    l.ID_SYMPTOM AS symptom_id, l.ID_MEDICINE AS medicine_id,
                    l.LEVEL AS level, l.DESCR AS descr,
                    COALESCE(s.NAME, \'\') AS symptom_name,
                    COALESCE(m.NAME, \'\') AS medicine_name
             FROM base_healths_logs l
             LEFT JOIN base_healths_symptoms s ON s.ID = l.ID_SYMPTOM AND s.ID_USER = l.ID_USER
             LEFT JOIN base_healths_medicines m ON m.ID = l.ID_MEDICINE AND m.ID_USER = l.ID_USER
             WHERE l.ID = ? AND l.ID_USER = ? LIMIT 1'
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
        $symptomId = (int)($post['symptom_id'] ?? $post['id_symptom'] ?? 0);
        $medicineId = (int)($post['medicine_id'] ?? $post['id_medicine'] ?? 0);
        $symptomName = trim((string)($post['symptom_name'] ?? ''));
        $medicineName = trim((string)($post['medicine_name'] ?? ''));

        if ($symptomId < 1 && $symptomName !== '') {
            $symptomId = HealthSymptomsRepository::findOrCreate($symptomName);
        }
        if ($medicineId < 1 && $medicineName !== '') {
            $medicineId = HealthMedicinesRepository::findOrCreate($medicineName);
        }
        if ($symptomId > 0 && !HealthSymptomsRepository::findOwned($symptomId)) {
            throw new InvalidArgumentException(t('wellness.err.symptom_invalid'));
        }
        if ($medicineId > 0 && !HealthMedicinesRepository::findOwned($medicineId)) {
            throw new InvalidArgumentException(t('wellness.err.medicine_invalid'));
        }

        $level = max(0, min(5, (int)($post['level'] ?? 0)));
        return [
            'dt' => WellnessOwner::parseDt($post['dt'] ?? null),
            'symptom_id' => $symptomId,
            'medicine_id' => $medicineId,
            'level' => $level,
            'descr' => mb_substr(trim((string)($post['descr'] ?? '')), 0, 255),
        ];
    }

    /** @param array<string,mixed> $data */
    public static function create(array $data): int
    {
        $st = Database::pdo()->prepare(
            'INSERT INTO base_healths_logs
             (DT_CREATE, DT, ID_USER, ID_SYMPTOM, ID_MEDICINE, LEVEL, DESCR)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $st->execute([
            time(),
            $data['dt'],
            WellnessOwner::userId(),
            $data['symptom_id'],
            $data['medicine_id'],
            $data['level'],
            $data['descr'],
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
            'UPDATE base_healths_logs
             SET DT = ?, ID_SYMPTOM = ?, ID_MEDICINE = ?, LEVEL = ?, DESCR = ?
             WHERE ID = ? AND ID_USER = ?'
        );
        $st->execute([
            $data['dt'],
            $data['symptom_id'],
            $data['medicine_id'],
            $data['level'],
            $data['descr'],
            $id,
            WellnessOwner::userId(),
        ]);
    }

    /** @param list<int> $ids @return int */
    public static function deleteByIds(array $ids): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $i): bool => $i > 0)));
        if ($ids === [] || !Database::tableExists('base_healths_logs')) {
            return 0;
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $params = $ids;
        $params[] = WellnessOwner::userId();
        $st = Database::pdo()->prepare("DELETE FROM base_healths_logs WHERE ID IN ($ph) AND ID_USER = ?");
        $st->execute($params);
        return $st->rowCount();
    }
}
