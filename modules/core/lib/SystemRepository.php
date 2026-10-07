<?php
declare(strict_types=1);

/**
 * Platform row in base_system (company / instance chrome).
 * Product columns (site SEO, flyer/promo, calendar hours) are written by owning modules.
 */
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

    /** Platform instance label (FreeSaaS /settings). */
    public static function updateBasics(array $data): void
    {
        $row = self::get();
        if (!$row) {
            throw new RuntimeException('base_system is empty — run data migrate');
        }
        $company = (string)($data['company'] ?? '');
        Database::pdo()->prepare(
            'UPDATE base_system SET company = ? WHERE id = ?'
        )->execute([$company, (int)$row['id']]);
        self::$companyNameCache = null;
    }
}
