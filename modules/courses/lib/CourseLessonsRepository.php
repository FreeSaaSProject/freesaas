<?php
declare(strict_types=1);

final class CourseLessonsRepository
{
    /** @return list<array<string,mixed>> */
    public static function listForProgram(int $programId): array
    {
        if ($programId < 1 || !Database::tableExists('base_course_lessons')) {
            return [];
        }
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, PROGRAM_ID AS program_id, MODULE_ID AS module_id, NAME AS name,
                    BODY AS body, SORT AS sort, DRIP_DAYS AS drip_days, ENABLED AS enabled
             FROM base_course_lessons WHERE PROGRAM_ID = ? ORDER BY SORT ASC, ID ASC'
        );
        $st->execute([$programId]);
        $out = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $row['id'] = (int)$row['id'];
            $row['program_id'] = (int)$row['program_id'];
            $row['module_id'] = (int)$row['module_id'];
            $row['sort'] = (int)$row['sort'];
            $row['drip_days'] = (int)$row['drip_days'];
            $row['enabled'] = (int)$row['enabled'];
            $out[] = $row;
        }
        return $out;
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        if ($id < 1 || !Database::tableExists('base_course_lessons')) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, PROGRAM_ID AS program_id, MODULE_ID AS module_id, NAME AS name,
                    BODY AS body, SORT AS sort, DRIP_DAYS AS drip_days, ENABLED AS enabled
             FROM base_course_lessons WHERE ID = ? LIMIT 1'
        );
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $row['id'] = (int)$row['id'];
        $row['program_id'] = (int)$row['program_id'];
        $row['module_id'] = (int)$row['module_id'];
        $row['sort'] = (int)$row['sort'];
        $row['drip_days'] = (int)$row['drip_days'];
        $row['enabled'] = (int)$row['enabled'];
        return $row;
    }

    /** @param array<string,mixed> $data */
    public static function save(int $id, array $data): int
    {
        if ($id < 1) {
            $st = Database::pdo()->prepare(
                'INSERT INTO base_course_lessons
                 (PROGRAM_ID, MODULE_ID, NAME, BODY, SORT, DRIP_DAYS, ENABLED)
                 VALUES (?,?,?,?,?,?,?)'
            );
            $st->execute([
                (int)($data['program_id'] ?? 0),
                (int)($data['module_id'] ?? 0),
                (string)($data['name'] ?? ''),
                (string)($data['body'] ?? ''),
                (int)($data['sort'] ?? 0),
                max(0, (int)($data['drip_days'] ?? 0)),
                !empty($data['enabled']) ? 1 : 0,
            ]);
            return (int)Database::pdo()->lastInsertId();
        }
        $st = Database::pdo()->prepare(
            'UPDATE base_course_lessons
             SET NAME=?, BODY=?, SORT=?, DRIP_DAYS=?, ENABLED=?, MODULE_ID=?
             WHERE ID=? AND PROGRAM_ID=?'
        );
        $st->execute([
            (string)($data['name'] ?? ''),
            (string)($data['body'] ?? ''),
            (int)($data['sort'] ?? 0),
            max(0, (int)($data['drip_days'] ?? 0)),
            !empty($data['enabled']) ? 1 : 0,
            (int)($data['module_id'] ?? 0),
            $id,
            (int)($data['program_id'] ?? 0),
        ]);
        return $id;
    }

    public static function delete(int $id): void
    {
        if ($id < 1) {
            return;
        }
        Database::pdo()->prepare('DELETE FROM base_course_lessons WHERE ID = ?')->execute([$id]);
    }
}
