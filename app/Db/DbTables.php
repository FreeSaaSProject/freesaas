<?php
declare(strict_types=1);

/** DB hub: table inventory, validation, expert/normal mode. */
final class DbTables
{
    public const PREF_MODE = 'db_tools_mode';
    public const MODE_NORMAL = 'normal';
    public const MODE_EXPERT = 'expert';

    /**
     * Union of module.json tables[] from all discovered modules (may not exist in DB yet).
     * Use for restore into empty/partial schema.
     *
     * @return list<string>
     */
    public static function declaredTables(): array
    {
        $names = [];
        foreach (ModuleManager::all() as $meta) {
            $tables = $meta['tables'] ?? [];
            if (!is_array($tables)) {
                continue;
            }
            foreach ($tables as $t) {
                if (!is_string($t) || $t === '' || !self::isValidTableName($t)) {
                    continue;
                }
                $names[$t] = true;
            }
        }
        $out = array_keys($names);
        sort($out, SORT_STRING);
        return $out;
    }

    /**
     * Union of module.json tables[] from all discovered modules that exist in the DB.
     *
     * @return list<string>
     */
    public static function ownedTables(): array
    {
        $out = [];
        foreach (self::declaredTables() as $t) {
            if (Database::tableExists($t)) {
                $out[] = $t;
            }
        }
        return $out;
    }

    /**
     * All base tables in the current connect database.
     *
     * @return list<string>
     */
    public static function allTables(): array
    {
        $st = Database::pdo()->query('SHOW FULL TABLES WHERE Table_type = \'BASE TABLE\'');
        $out = [];
        while ($row = $st->fetch(PDO::FETCH_NUM)) {
            $name = (string)($row[0] ?? '');
            if ($name !== '' && self::isValidTableName($name)) {
                $out[] = $name;
            }
        }
        sort($out, SORT_STRING);
        return $out;
    }

    /**
     * @return list<string>
     */
    public static function foreignTables(): array
    {
        $owned = array_fill_keys(self::ownedTables(), true);
        $out = [];
        foreach (self::allTables() as $t) {
            if (!isset($owned[$t])) {
                $out[] = $t;
            }
        }
        return $out;
    }

    /**
     * Fast inventory via SHOW TABLE STATUS only (no CHECK TABLE).
     * InnoDB Rows are approximate. Size = Data_length + Index_length.
     *
     * @return list<array{
     *   table:string,
     *   owned:bool,
     *   rows:int,
     *   data_bytes:int,
     *   index_bytes:int,
     *   total_bytes:int,
     *   engine:string
     * }>
     */
    public static function tableInventory(): array
    {
        $owned = array_fill_keys(self::ownedTables(), true);
        $st = Database::pdo()->query('SHOW TABLE STATUS');
        $out = [];
        if ($st === false) {
            return [];
        }
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $name = (string)($row['Name'] ?? '');
            if ($name === '' || !self::isValidTableName($name)) {
                continue;
            }
            $data = (int)($row['Data_length'] ?? 0);
            $index = (int)($row['Index_length'] ?? 0);
            $out[] = [
                'table' => $name,
                'owned' => isset($owned[$name]),
                'rows' => (int)($row['Rows'] ?? 0),
                'data_bytes' => $data,
                'index_bytes' => $index,
                'total_bytes' => $data + $index,
                'engine' => (string)($row['Engine'] ?? ''),
            ];
        }
        usort($out, static function (array $a, array $b): int {
            return strcmp($a['table'], $b['table']);
        });
        return $out;
    }

    public static function isValidTableName(string $name): bool
    {
        return (bool)preg_match('/^[A-Za-z0-9_]+$/', $name);
    }

    /**
     * @param list<string> $tables
     * @return list<string>
     */
    public static function filterValidTables(array $tables): array
    {
        $out = [];
        $seen = [];
        foreach ($tables as $t) {
            if (!is_string($t) || $t === '' || !self::isValidTableName($t)) {
                continue;
            }
            if (isset($seen[$t])) {
                continue;
            }
            $seen[$t] = true;
            $out[] = $t;
        }
        return $out;
    }

    /**
     * @param list<string> $tables requested
     * @param list<string> $allowed whitelist
     * @return list<string>
     */
    public static function intersectTables(array $tables, array $allowed): array
    {
        $allow = array_fill_keys($allowed, true);
        $out = [];
        foreach (self::filterValidTables($tables) as $t) {
            if (isset($allow[$t])) {
                $out[] = $t;
            }
        }
        return $out;
    }

    public static function modeForUser(int $userId): string
    {
        if (!class_exists('UserPrefsRepository', false)) {
            require_once base_path('modules/core/lib/UserPrefsRepository.php');
        }
        $mode = UserPrefsRepository::get($userId, self::PREF_MODE);
        return $mode === self::MODE_EXPERT ? self::MODE_EXPERT : self::MODE_NORMAL;
    }

    public static function setModeForUser(int $userId, string $mode): void
    {
        if (!class_exists('UserPrefsRepository', false)) {
            require_once base_path('modules/core/lib/UserPrefsRepository.php');
        }
        $mode = $mode === self::MODE_EXPERT ? self::MODE_EXPERT : self::MODE_NORMAL;
        UserPrefsRepository::set($userId, self::PREF_MODE, $mode);
    }

}
