<?php
declare(strict_types=1);

/**
 * Sync recipe items → per-portion macros + synthetic base_fit_foods row (per 100 g cooked).
 */
final class FitRecipeSync
{
    /**
     * @return array{ok:bool,error?:string,food_id?:int,food_row?:array<string,mixed>}
     */
    public static function ensureFoodForDiary(int $recipeId): array
    {
        $sync = self::syncFromItems($recipeId);
        if (!empty($sync['ok']) && (int)($sync['food_id'] ?? 0) > 0) {
            $food = FitFoodsRepository::findRaw((int)$sync['food_id']);
            if ($food !== null) {
                return ['ok' => true, 'food_id' => (int)$food['id'], 'food_row' => $food];
            }
        }
        $manual = self::syncManualFallback($recipeId);
        if (!empty($manual['ok']) && (int)($manual['food_id'] ?? 0) > 0) {
            $food = FitFoodsRepository::findRaw((int)$manual['food_id']);
            if ($food !== null) {
                return ['ok' => true, 'food_id' => (int)$food['id'], 'food_row' => $food];
            }
            return ['ok' => false, 'error' => 'manual_failed'];
        }
        return ['ok' => false, 'error' => (string)($sync['error'] ?? $manual['error'] ?? 'need_cooked_weight')];
    }

    /**
     * @return array<string,mixed>
     */
    public static function syncFromItems(int $recipeId): array
    {
        $recipe = FitRecipesRepository::find($recipeId);
        if ($recipe === null) {
            return ['ok' => false, 'error' => 'recipe_not_found'];
        }
        $items = FitRecipesRepository::loadItems($recipeId);
        if ($items === []) {
            if (Database::columnExists('base_fit_foods', 'ID_SOURCE_RECIPE')) {
                Database::pdo()->prepare('DELETE FROM base_fit_foods WHERE ID_SOURCE_RECIPE = ?')->execute([$recipeId]);
            }
            Database::pdo()->prepare('UPDATE base_fit_recipes SET ID_FOOD = NULL WHERE ID = ?')->execute([$recipeId]);
            return ['ok' => true, 'empty' => true];
        }

        $sumC = $sumP = $sumF = $sumU = $sumGrams = 0.0;
        foreach ($items as $it) {
            $g = (float)($it['grams'] ?? 0);
            if ($g <= 0) {
                continue;
            }
            $sumGrams += $g;
            $k = $g / 100.0;
            $sumC += (float)($it['food_ccals'] ?? 0) * $k;
            $sumP += (float)($it['food_proteins'] ?? 0) * $k;
            $sumF += (float)($it['food_fats'] ?? 0) * $k;
            $sumU += (float)($it['food_carbs'] ?? 0) * $k;
        }
        if ($sumGrams <= 0) {
            return ['ok' => false, 'error' => 'no_valid_ingredients'];
        }

        $portions = max(1, (int)($recipe['portions'] ?? 1));
        $cooked = (float)($recipe['weight_cooked_g'] ?? 0);
        if ($cooked <= 0) {
            $cooked = $sumGrams;
        }
        $per100 = [
            'ccals' => ($sumC / $cooked) * 100.0,
            'proteins' => ($sumP / $cooked) * 100.0,
            'fats' => ($sumF / $cooked) * 100.0,
            'carbs' => ($sumU / $cooked) * 100.0,
        ];
        $pp = [
            'ccals' => $sumC / $portions,
            'proteins' => $sumP / $portions,
            'fats' => $sumF / $portions,
            'carbs' => $sumU / $portions,
        ];

        $foodId = self::upsertSyntheticFood(
            $recipeId,
            (int)$recipe['user_id'],
            (string)$recipe['name'],
            $per100,
            (int)($recipe['food_id'] ?? 0)
        );
        if ($foodId < 1) {
            return ['ok' => false, 'error' => 'food_upsert_failed'];
        }

        $st = Database::pdo()->prepare(
            'UPDATE base_fit_recipes
             SET CCALS = ?, PROTEINS = ?, FATS = ?, CARBS = ?, ID_FOOD = ?, SOURCE = ?
             WHERE ID = ?'
        );
        $st->execute([
            round($pp['ccals'], 2),
            round($pp['proteins'], 2),
            round($pp['fats'], 2),
            round($pp['carbs'], 2),
            $foodId,
            'computed',
            $recipeId,
        ]);

        return [
            'ok' => true,
            'food_id' => $foodId,
            'cooked_g' => $cooked,
            'per_100' => $per100,
            'per_portion' => $pp,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public static function syncManualFallback(int $recipeId): array
    {
        $recipe = FitRecipesRepository::find($recipeId);
        if ($recipe === null) {
            return ['ok' => false, 'error' => 'recipe_not_found'];
        }
        $cooked = (float)($recipe['weight_cooked_g'] ?? 0);
        $portions = max(1, (int)($recipe['portions'] ?? 1));
        if ($cooked <= 0) {
            return ['ok' => false, 'error' => 'need_cooked_weight'];
        }
        $ppC = (float)($recipe['ccals'] ?? 0);
        $ppP = (float)($recipe['proteins'] ?? 0);
        $ppF = (float)($recipe['fats'] ?? 0);
        $ppU = (float)($recipe['carbs'] ?? 0);
        if ($ppC <= 0 && $ppP <= 0 && $ppF <= 0 && $ppU <= 0) {
            return ['ok' => false, 'error' => 'manual_failed'];
        }
        $totalC = $ppC * $portions;
        $totalP = $ppP * $portions;
        $totalF = $ppF * $portions;
        $totalU = $ppU * $portions;
        $per100 = [
            'ccals' => ($totalC / $cooked) * 100.0,
            'proteins' => ($totalP / $cooked) * 100.0,
            'fats' => ($totalF / $cooked) * 100.0,
            'carbs' => ($totalU / $cooked) * 100.0,
        ];
        $foodId = self::upsertSyntheticFood(
            $recipeId,
            (int)$recipe['user_id'],
            (string)$recipe['name'],
            $per100,
            (int)($recipe['food_id'] ?? 0)
        );
        if ($foodId < 1) {
            return ['ok' => false, 'error' => 'manual_failed'];
        }
        Database::pdo()->prepare('UPDATE base_fit_recipes SET ID_FOOD = ?, SOURCE = ? WHERE ID = ?')
            ->execute([$foodId, 'manual', $recipeId]);
        return ['ok' => true, 'food_id' => $foodId];
    }

    /**
     * @param array{ccals:float,proteins:float,fats:float,carbs:float} $per100
     */
    private static function upsertSyntheticFood(
        int $recipeId,
        int $userId,
        string $recipeName,
        array $per100,
        int $existingFoodId
    ): int {
        if (!Database::tableExists('base_fit_foods') || !Database::columnExists('base_fit_foods', 'ID_SOURCE_RECIPE')) {
            return 0;
        }
        $name = 'Рецепт: ' . ($recipeName !== '' ? $recipeName : ('#' . $recipeId));
        $name = mb_substr($name, 0, 255);
        $target = 0;
        if ($existingFoodId > 0) {
            $st = Database::pdo()->prepare(
                'SELECT ID, ID_SOURCE_RECIPE FROM base_fit_foods WHERE ID = ? LIMIT 1'
            );
            $st->execute([$existingFoodId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (is_array($row) && ((int)($row['ID_SOURCE_RECIPE'] ?? 0) === $recipeId || (int)($row['ID_SOURCE_RECIPE'] ?? 0) === 0)) {
                $target = $existingFoodId;
            }
        }
        if ($target < 1) {
            $st = Database::pdo()->prepare(
                'SELECT ID FROM base_fit_foods WHERE ID_SOURCE_RECIPE = ? LIMIT 1'
            );
            $st->execute([$recipeId]);
            $target = (int)$st->fetchColumn();
        }

        $c = (int)round($per100['ccals']);
        $p = round($per100['proteins'], 2);
        $f = round($per100['fats'], 2);
        $u = round($per100['carbs'], 2);

        if ($target > 0) {
            Database::pdo()->prepare(
                'UPDATE base_fit_foods
                 SET NAME = ?, CCALS = ?, PROTEINS = ?, FATS = ?, CARBS = ?, ID_SOURCE_RECIPE = ?, ID_USER = ?
                 WHERE ID = ?'
            )->execute([$name, $c, $p, $f, $u, $recipeId, $userId, $target]);
            return $target;
        }

        return WellnessOwner::insertOwned('base_fit_foods', [
            'NAME' => $name,
            'CCALS' => $c,
            'CARBS' => $u,
            'PROTEINS' => $p,
            'FATS' => $f,
            'ID_SOURCE_RECIPE' => $recipeId,
        ], $userId);
    }
}
