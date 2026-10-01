<?php
declare(strict_types=1);

final class SystemRepository
{
    public static function get(): ?array
    {
        if (!Database::tableExists('base_system')) {
            return null;
        }
        $row = Database::pdo()->query('SELECT * FROM base_system ORDER BY id ASC LIMIT 1')->fetch();
        return $row ?: null;
    }

    public static function updateBasics(array $data): void
    {
        $row = self::get();
        if (!$row) {
            throw new RuntimeException('base_system is empty — run data migrate');
        }
        $sets = [
            'title' => (string)($data['title'] ?? ''),
            'company' => (string)($data['company'] ?? ''),
            'name' => (string)($data['name'] ?? ''),
            'tel' => (string)($data['tel'] ?? ''),
        ];
        $sql = 'UPDATE base_system SET title = ?, company = ?, name = ?, tel = ?';
        $params = [$sets['title'], $sets['company'], $sets['name'], $sets['tel']];
        if (Database::columnExists('base_system', 'work_hour_start')
            && Database::columnExists('base_system', 'work_hour_end')
            && array_key_exists('work_hour_start', $data)
        ) {
            $start = max(0, min(23, (int)$data['work_hour_start']));
            $end = max(0, min(23, (int)$data['work_hour_end']));
            if ($end < $start) {
                $end = $start;
            }
            $sql .= ', work_hour_start = ?, work_hour_end = ?';
            $params[] = $start;
            $params[] = $end;
        }
        $sql .= ' WHERE id = ?';
        $params[] = (int)$row['id'];
        Database::pdo()->prepare($sql)->execute($params);
    }
}
