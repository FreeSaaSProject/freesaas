<?php
declare(strict_types=1);

/**
 * CRUD for custom KPI family JSON recipes.
 */
final class BiFamilyDefsRepository
{
    public static function tableReady(): bool
    {
        return Database::tableExists('base_bi_family_defs');
    }

    public static function hasRecipeColumn(): bool
    {
        return self::tableReady() && Database::columnExists('base_bi_family_defs', 'RECIPE_JSON');
    }

    public static function safeId(string $id): string
    {
        $id = strtolower(trim($id));
        $id = preg_replace('/[^a-z0-9_]/', '', $id) ?? '';
        return $id;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function listAll(): array
    {
        if (!self::tableReady()) {
            return [];
        }
        $rows = Database::pdo()->query(
            'SELECT * FROM base_bi_family_defs ORDER BY NAME ASC, ID ASC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $out[] = self::hydrate($row);
        }
        return $out;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function listEnabled(): array
    {
        $out = [];
        foreach (self::listAll() as $row) {
            if (!empty($row['ENABLED']) && !empty($row['LAST_VALIDATE_OK'])) {
                $out[] = $row;
            }
        }
        return $out;
    }

    public static function findByFamilyId(string $familyId): ?array
    {
        if (!self::tableReady()) {
            return null;
        }
        $familyId = self::safeId($familyId);
        if ($familyId === '') {
            return null;
        }
        $st = Database::pdo()->prepare('SELECT * FROM base_bi_family_defs WHERE FAMILY_ID = ? LIMIT 1');
        $st->execute([$familyId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ? self::hydrate($row) : null;
    }

    public static function find(int $id): ?array
    {
        if ($id < 1 || !self::tableReady()) {
            return null;
        }
        $st = Database::pdo()->prepare('SELECT * FROM base_bi_family_defs WHERE ID = ? LIMIT 1');
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ? self::hydrate($row) : null;
    }

    /**
     * @param array{FAMILY_ID:string,NAME:string,RECIPE_JSON:string|array,ENABLED?:int,BUILTIN_FORK?:string,LAST_VALIDATE_OK?:int,LAST_VALIDATE_MSG?:string} $data
     */
    public static function save(array $data, int $id = 0): int
    {
        if (!self::tableReady()) {
            throw new RuntimeException('family_defs_missing');
        }
        if (!self::hasRecipeColumn()) {
            throw new RuntimeException('recipe_column_missing');
        }
        $familyId = self::safeId((string)($data['FAMILY_ID'] ?? ''));
        if ($familyId === '' || strlen($familyId) < 2) {
            throw new InvalidArgumentException('invalid_family_id');
        }
        // Shipped ids may be saved as DB overrides (same FAMILY_ID upsert via findByFamilyId).
        $name = trim((string)($data['NAME'] ?? ''));
        if ($name === '') {
            $name = $familyId;
        }
        $recipeRaw = $data['RECIPE_JSON'] ?? '{}';
        if (is_array($recipeRaw)) {
            $recipeJson = json_encode($recipeRaw, JSON_UNESCAPED_UNICODE);
        } else {
            $recipeJson = (string)$recipeRaw;
        }
        if ($recipeJson === false || $recipeJson === '') {
            throw new InvalidArgumentException('invalid_recipe_json');
        }
        $now = time();
        $ok = !empty($data['LAST_VALIDATE_OK']) ? 1 : 0;
        $msg = (string)($data['LAST_VALIDATE_MSG'] ?? '');
        $enabled = !isset($data['ENABLED']) || !empty($data['ENABLED']) ? 1 : 0;
        $fork = trim((string)($data['BUILTIN_FORK'] ?? ''));

        $existing = $id > 0 ? self::find($id) : self::findByFamilyId($familyId);
        if ($existing !== null) {
            $id = (int)$existing['ID'];
            $st = Database::pdo()->prepare(
                'UPDATE base_bi_family_defs
                 SET FAMILY_ID=?, NAME=?, RECIPE_JSON=?, ENABLED=?, BUILTIN_FORK=?,
                     LAST_VALIDATE_OK=?, LAST_VALIDATE_MSG=?, DT_UPDATE=?
                 WHERE ID=?'
            );
            $st->execute([$familyId, $name, $recipeJson, $enabled, $fork, $ok, $msg, $now, $id]);
        } else {
            $st = Database::pdo()->prepare(
                'INSERT INTO base_bi_family_defs
                 (FAMILY_ID, NAME, RECIPE_JSON, ENABLED, BUILTIN_FORK, LAST_VALIDATE_OK, LAST_VALIDATE_MSG, ID_CREATOR, DT_CREATE, DT_UPDATE)
                 VALUES (?,?,?,?,?,?,?,?,?,?)'
            );
            $st->execute([
                $familyId, $name, $recipeJson, $enabled, $fork, $ok, $msg,
                Auth::userId(), $now, $now,
            ]);
            $id = (int)Database::pdo()->lastInsertId();
        }

        ReportRegistry::reloadCustoms();
        return $id;
    }

    public static function delete(int $id): bool
    {
        $row = self::find($id);
        if ($row === null) {
            return false;
        }
        $st = Database::pdo()->prepare('DELETE FROM base_bi_family_defs WHERE ID = ?');
        $st->execute([$id]);
        ReportRegistry::reloadCustoms();
        return true;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function hydrate(array $row): array
    {
        $json = (string)($row['RECIPE_JSON'] ?? '{}');
        $decoded = json_decode($json, true);
        return [
            'ID' => (int)($row['ID'] ?? 0),
            'FAMILY_ID' => (string)($row['FAMILY_ID'] ?? ''),
            'NAME' => (string)($row['NAME'] ?? ''),
            'RECIPE_JSON' => $json,
            'RECIPE' => is_array($decoded) ? $decoded : [],
            'ENABLED' => (int)($row['ENABLED'] ?? 0),
            'BUILTIN_FORK' => (string)($row['BUILTIN_FORK'] ?? ''),
            'LAST_VALIDATE_OK' => (int)($row['LAST_VALIDATE_OK'] ?? 0),
            'LAST_VALIDATE_MSG' => (string)($row['LAST_VALIDATE_MSG'] ?? ''),
            'ID_CREATOR' => (int)($row['ID_CREATOR'] ?? 0),
            'DT_CREATE' => (int)($row['DT_CREATE'] ?? 0),
            'DT_UPDATE' => (int)($row['DT_UPDATE'] ?? 0),
            'custom' => true,
        ];
    }
}
