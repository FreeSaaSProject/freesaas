<?php
declare(strict_types=1);

final class FitRecipesRepository
{
    public static function tableReady(): bool
    {
        return Database::tableExists('base_fit_recipes');
    }

    /** @return list<array<string,mixed>> */
    public static function listForUser(int $userId, int $limit = 20, bool $super = false): array
    {
        if (!self::tableReady() || $userId < 1) {
            return [];
        }
        $limit = max(1, min(50, $limit));
        if ($super) {
            $st = Database::pdo()->query(
                'SELECT ID AS id, ID_USER AS user_id, NAME AS name, PORTIONS AS portions,
                        WEIGHT_COOKED_G AS weight_cooked_g, ID_FOOD AS food_id
                 FROM base_fit_recipes WHERE DISABLED = 0
                 ORDER BY NAME ASC LIMIT ' . $limit
            );
            return $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        }
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, ID_USER AS user_id, NAME AS name, PORTIONS AS portions,
                    WEIGHT_COOKED_G AS weight_cooked_g, ID_FOOD AS food_id
             FROM base_fit_recipes WHERE DISABLED = 0 AND ID_USER = ?
             ORDER BY NAME ASC LIMIT ' . $limit
        );
        $st->execute([$userId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        if ($id < 1 || !self::tableReady()) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, ID_USER AS user_id, DT_CREATE AS dt_create, NAME AS name,
                    PORTIONS AS portions, WEIGHT_COOKED_G AS weight_cooked_g,
                    CCALS AS ccals, PROTEINS AS proteins, FATS AS fats, CARBS AS carbs,
                    STEPS AS steps, INGREDIENTS AS ingredients, SOURCE AS source,
                    ID_FOOD AS food_id, DISABLED AS disabled
             FROM base_fit_recipes WHERE ID = ? LIMIT 1'
        );
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public static function canAccess(?array $row, int $userId, bool $super): bool
    {
        if ($row === null || (int)($row['id'] ?? 0) < 1) {
            return false;
        }
        if ($super) {
            return true;
        }
        return (int)($row['user_id'] ?? 0) === $userId;
    }

    public static function create(int $userId, string $name, int $portions = 1, ?float $cookedG = null): int
    {
        $name = trim($name);
        if ($userId < 1 || $name === '') {
            return 0;
        }
        $portions = max(1, $portions);
        $st = Database::pdo()->prepare(
            'INSERT INTO base_fit_recipes
             (ID_USER, DT_CREATE, NAME, PORTIONS, WEIGHT_COOKED_G, CCALS, PROTEINS, FATS, CARBS, SOURCE, DISABLED)
             VALUES (?, ?, ?, ?, ?, 0, 0, 0, 0, ?, 0)'
        );
        $st->execute([$userId, time(), mb_substr($name, 0, 255), $portions, $cookedG, 'manual']);
        return (int)Database::pdo()->lastInsertId();
    }

    public static function updateMeta(int $id, string $name, int $portions, ?float $cookedG): void
    {
        $st = Database::pdo()->prepare(
            'UPDATE base_fit_recipes SET NAME = ?, PORTIONS = ?, WEIGHT_COOKED_G = ? WHERE ID = ?'
        );
        $st->execute([mb_substr(trim($name), 0, 255), max(1, $portions), $cookedG, $id]);
    }

    /** @return list<array<string,mixed>> */
    public static function loadItems(int $recipeId): array
    {
        if ($recipeId < 1 || !Database::tableExists('base_fit_recipe_items')) {
            return [];
        }
        $st = Database::pdo()->prepare(
            'SELECT i.ID AS id, i.ID_FOOD AS food_id, i.GRAMS AS grams, i.SORT_ORDER AS sort_order,
                    COALESCE(f.NAME, \'\') AS food_name,
                    f.CCALS AS food_ccals, f.PROTEINS AS food_proteins, f.FATS AS food_fats, f.CARBS AS food_carbs
             FROM base_fit_recipe_items i
             LEFT JOIN base_fit_foods f ON f.ID = i.ID_FOOD
             WHERE i.ID_RECIPE = ?
             ORDER BY i.SORT_ORDER ASC, i.ID ASC'
        );
        $st->execute([$recipeId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @param list<array{food_id:int,grams:float}> $lines
     */
    public static function replaceItems(int $recipeId, array $lines): bool
    {
        if ($recipeId < 1 || !Database::tableExists('base_fit_recipe_items')) {
            return false;
        }
        $pdo = Database::pdo();
        $pdo->prepare('DELETE FROM base_fit_recipe_items WHERE ID_RECIPE = ?')->execute([$recipeId]);
        $ins = $pdo->prepare(
            'INSERT INTO base_fit_recipe_items (ID_RECIPE, ID_FOOD, GRAMS, SORT_ORDER) VALUES (?, ?, ?, ?)'
        );
        $sort = 0;
        foreach ($lines as $line) {
            $fid = (int)($line['food_id'] ?? 0);
            $g = (float)($line['grams'] ?? 0);
            if ($fid < 1 || $g <= 0) {
                continue;
            }
            $sort++;
            $ins->execute([$recipeId, $fid, $g, $sort]);
        }
        return true;
    }
}
