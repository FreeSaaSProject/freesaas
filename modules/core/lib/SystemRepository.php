<?php
declare(strict_types=1);

final class SystemRepository
{
    /** @var string|null */
    private static $companyNameCache = null;

    public static function get(): ?array
    {
        if (!Database::tableExists('base_system')) {
            return null;
        }
        $row = Database::pdo()->query('SELECT * FROM base_system ORDER BY id ASC LIMIT 1')->fetch();
        return $row ?: null;
    }

    /**
     * Short company label under the logo and in the browser tab.
     * Prefer base_system.company (Settings); fall back to config app.instance.
     */
    public static function companyName(): string
    {
        if (self::$companyNameCache !== null) {
            return self::$companyNameCache;
        }
        $row = self::get();
        $fromDb = trim((string)($row['company'] ?? ''));
        if ($fromDb !== '') {
            return self::$companyNameCache = $fromDb;
        }
        return self::$companyNameCache = trim((string)Config::get('app', 'instance', ''));
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
        $sql = 'UPDATE base_system SET title = ?, company = ?, name = ?, tel = ? WHERE id = ?';
        Database::pdo()->prepare($sql)->execute([
            $sets['title'],
            $sets['company'],
            $sets['name'],
            $sets['tel'],
            (int)$row['id'],
        ]);
        self::$companyNameCache = null;
    }

    /** Calendar working hours (base_system columns owned by calendar module). */
    public static function updateWorkHours(int $start, int $end): void
    {
        $row = self::get();
        if (!$row) {
            throw new RuntimeException('base_system is empty — run data migrate');
        }
        if (!Database::columnExists('base_system', 'work_hour_start')
            || !Database::columnExists('base_system', 'work_hour_end')
        ) {
            return;
        }
        $start = max(0, min(23, $start));
        $end = max(0, min(23, $end));
        if ($end < $start) {
            $end = $start;
        }
        Database::pdo()->prepare(
            'UPDATE base_system SET work_hour_start = ?, work_hour_end = ? WHERE id = ?'
        )->execute([$start, $end, (int)$row['id']]);
    }
}
