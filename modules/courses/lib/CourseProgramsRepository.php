<?php
declare(strict_types=1);

final class CourseProgramsRepository
{
    public static function ready(): bool
    {
        return Database::tableExists('base_course_programs');
    }

    /** @return list<array<string,mixed>> */
    public static function listAll(bool $enabledOnly = false): array
    {
        if (!self::ready()) {
            return [];
        }
        $sql = 'SELECT ID AS id, NAME AS name, DESCR AS descr, AUDIENCE AS audience,
                       PRODUCT_ID AS product_id, ACCESS_DAYS AS access_days, ENABLED AS enabled,
                       DT_CREATE AS dt_create, DT_UPDATE AS dt_update
                FROM base_course_programs';
        if ($enabledOnly) {
            $sql .= ' WHERE ENABLED = 1';
        }
        $sql .= ' ORDER BY ID DESC';
        $st = Database::pdo()->query($sql);
        $out = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $out[] = self::norm($row);
        }
        return $out;
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        if ($id < 1 || !self::ready()) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, NAME AS name, DESCR AS descr, AUDIENCE AS audience,
                    PRODUCT_ID AS product_id, ACCESS_DAYS AS access_days, ENABLED AS enabled,
                    DT_CREATE AS dt_create, DT_UPDATE AS dt_update
             FROM base_course_programs WHERE ID = ? LIMIT 1'
        );
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ? self::norm($row) : null;
    }

    /** @return array<string,mixed>|null */
    public static function findByProductId(int $productId): ?array
    {
        if ($productId < 1 || !self::ready()) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, NAME AS name, DESCR AS descr, AUDIENCE AS audience,
                    PRODUCT_ID AS product_id, ACCESS_DAYS AS access_days, ENABLED AS enabled,
                    DT_CREATE AS dt_create, DT_UPDATE AS dt_update
             FROM base_course_programs WHERE PRODUCT_ID = ? AND ENABLED = 1 ORDER BY ID DESC LIMIT 1'
        );
        $st->execute([$productId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ? self::norm($row) : null;
    }

    /** @param array<string,mixed> $data */
    public static function save(int $id, array $data): int
    {
        $now = time();
        $audience = (string)($data['audience'] ?? 'clients');
        if (!in_array($audience, ['clients', 'staff', 'both'], true)) {
            $audience = 'clients';
        }
        if ($id < 1) {
            $st = Database::pdo()->prepare(
                'INSERT INTO base_course_programs
                 (NAME, DESCR, AUDIENCE, PRODUCT_ID, ACCESS_DAYS, ENABLED, DT_CREATE, DT_UPDATE)
                 VALUES (?,?,?,?,?,?,?,?)'
            );
            $st->execute([
                (string)($data['name'] ?? ''),
                (string)($data['descr'] ?? ''),
                $audience,
                (int)($data['product_id'] ?? 0),
                max(0, (int)($data['access_days'] ?? 0)),
                !empty($data['enabled']) ? 1 : 0,
                $now,
                $now,
            ]);
            return (int)Database::pdo()->lastInsertId();
        }
        $st = Database::pdo()->prepare(
            'UPDATE base_course_programs
             SET NAME=?, DESCR=?, AUDIENCE=?, PRODUCT_ID=?, ACCESS_DAYS=?, ENABLED=?, DT_UPDATE=?
             WHERE ID=?'
        );
        $st->execute([
            (string)($data['name'] ?? ''),
            (string)($data['descr'] ?? ''),
            $audience,
            (int)($data['product_id'] ?? 0),
            max(0, (int)($data['access_days'] ?? 0)),
            !empty($data['enabled']) ? 1 : 0,
            $now,
            $id,
        ]);
        return $id;
    }

    /** @param array<string,mixed> $row */
    private static function norm(array $row): array
    {
        $row['id'] = (int)$row['id'];
        $row['product_id'] = (int)$row['product_id'];
        $row['access_days'] = (int)$row['access_days'];
        $row['enabled'] = (int)$row['enabled'];
        $row['dt_create'] = (int)$row['dt_create'];
        $row['dt_update'] = (int)$row['dt_update'];
        return $row;
    }
}
