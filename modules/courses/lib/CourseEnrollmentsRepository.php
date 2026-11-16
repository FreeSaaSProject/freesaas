<?php
declare(strict_types=1);

final class CourseEnrollmentsRepository
{
    /**
     * @param array{program_id?:int,subject_type?:string,subject_id?:int,limit?:int} $opts
     * @return list<array<string,mixed>>
     */
    public static function list(array $opts = []): array
    {
        if (!Database::tableExists('base_course_enrollments')) {
            return [];
        }
        $where = ['1=1'];
        $params = [];
        if (($pid = (int)($opts['program_id'] ?? 0)) > 0) {
            $where[] = 'e.PROGRAM_ID = ?';
            $params[] = $pid;
        }
        $stype = (string)($opts['subject_type'] ?? '');
        if ($stype !== '') {
            $where[] = 'e.SUBJECT_TYPE = ?';
            $params[] = $stype;
        }
        if (($sid = (int)($opts['subject_id'] ?? 0)) > 0) {
            $where[] = 'e.SUBJECT_ID = ?';
            $params[] = $sid;
        }
        $limit = max(1, min(500, (int)($opts['limit'] ?? 100)));
        $sql = 'SELECT e.ID AS id, e.PROGRAM_ID AS program_id, e.SUBJECT_TYPE AS subject_type,
                       e.SUBJECT_ID AS subject_id, e.ACCESS_FROM AS access_from, e.ACCESS_UNTIL AS access_until,
                       e.SOURCE AS source, e.ORDER_ID AS order_id, e.DT_CREATE AS dt_create,
                       p.NAME AS program_name
                FROM base_course_enrollments e
                LEFT JOIN base_course_programs p ON p.ID = e.PROGRAM_ID
                WHERE ' . implode(' AND ', $where) . "
                ORDER BY e.ID DESC LIMIT $limit";
        $st = Database::pdo()->prepare($sql);
        $st->execute($params);
        $out = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $out[] = self::norm($row);
        }
        return $out;
    }

    /** @return array<string,mixed>|null */
    public static function findActive(string $subjectType, int $subjectId, int $programId): ?array
    {
        if ($subjectId < 1 || $programId < 1) {
            return null;
        }
        $now = time();
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, PROGRAM_ID AS program_id, SUBJECT_TYPE AS subject_type, SUBJECT_ID AS subject_id,
                    ACCESS_FROM AS access_from, ACCESS_UNTIL AS access_until, SOURCE AS source,
                    ORDER_ID AS order_id, DT_CREATE AS dt_create
             FROM base_course_enrollments
             WHERE SUBJECT_TYPE = ? AND SUBJECT_ID = ? AND PROGRAM_ID = ?
               AND ACCESS_FROM <= ? AND (ACCESS_UNTIL = 0 OR ACCESS_UNTIL >= ?)
             ORDER BY ID DESC LIMIT 1'
        );
        $st->execute([$subjectType, $subjectId, $programId, $now, $now]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ? self::norm($row) : null;
    }

    /**
     * @param array{program_id:int,subject_type:string,subject_id:int,source?:string,order_id?:int,access_days?:int} $data
     */
    public static function grant(array $data): int
    {
        $programId = (int)($data['program_id'] ?? 0);
        $subjectType = (string)($data['subject_type'] ?? 'client');
        if (!in_array($subjectType, ['client', 'staff'], true)) {
            $subjectType = 'client';
        }
        $subjectId = (int)($data['subject_id'] ?? 0);
        if ($programId < 1 || $subjectId < 1) {
            throw new InvalidArgumentException('program_id and subject_id required');
        }
        $existing = self::findActive($subjectType, $subjectId, $programId);
        if ($existing !== null) {
            return (int)$existing['id'];
        }
        $days = (int)($data['access_days'] ?? 0);
        if ($days < 1) {
            $prog = CourseProgramsRepository::find($programId);
            $days = $prog ? (int)$prog['access_days'] : 0;
        }
        $from = time();
        $until = $days > 0 ? $from + ($days * 86400) : 0;
        $st = Database::pdo()->prepare(
            'INSERT INTO base_course_enrollments
             (PROGRAM_ID, SUBJECT_TYPE, SUBJECT_ID, ACCESS_FROM, ACCESS_UNTIL, SOURCE, ORDER_ID, DT_CREATE)
             VALUES (?,?,?,?,?,?,?,?)'
        );
        $st->execute([
            $programId,
            $subjectType,
            $subjectId,
            $from,
            $until,
            (string)($data['source'] ?? 'manual'),
            (int)($data['order_id'] ?? 0),
            $from,
        ]);
        return (int)Database::pdo()->lastInsertId();
    }

    public static function revoke(int $id): void
    {
        if ($id < 1) {
            return;
        }
        Database::pdo()->prepare(
            'UPDATE base_course_enrollments SET ACCESS_UNTIL = ? WHERE ID = ?'
        )->execute([time() - 1, $id]);
    }

    /** @param array<string,mixed> $row */
    private static function norm(array $row): array
    {
        foreach (['id', 'program_id', 'subject_id', 'access_from', 'access_until', 'order_id', 'dt_create'] as $k) {
            if (isset($row[$k])) {
                $row[$k] = (int)$row[$k];
            }
        }
        return $row;
    }
}
