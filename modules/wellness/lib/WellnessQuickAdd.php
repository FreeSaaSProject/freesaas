<?php
declare(strict_types=1);

/**
 * Inline quick-add for food / health diaries (elos63 manage bar).
 */
final class WellnessQuickAdd
{
    private const FLASH_KEY = 'wellness_quick_flash';

    /**
     * @param array<string,mixed> $post
     * @return array{ok:bool,message:string}|null null when POST is not a quick-add
     */
    public static function tryHandle(array $post): ?array
    {
        $kind = trim((string)($post['wellness_quick'] ?? ''));
        if ($kind === '') {
            return null;
        }
        if (!Auth::can('wellness.edit')) {
            return ['ok' => false, 'message' => t('wellness.err.forbidden')];
        }
        try {
            if ($kind === 'food') {
                $id = self::addFood($post);
                return ['ok' => true, 'message' => t('wellness.quick.food_ok', ['id' => (string)$id])];
            }
            if ($kind === 'health') {
                $id = self::addHealth($post);
                return ['ok' => true, 'message' => t('wellness.quick.health_ok', ['id' => (string)$id])];
            }
            return ['ok' => false, 'message' => t('wellness.err.not_found')];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /** @param array{ok:bool,message:string} $flash */
    public static function storeFlash(array $flash): void
    {
        $_SESSION[self::FLASH_KEY] = $flash;
    }

    /** @return array{ok:bool,message:string}|null */
    public static function consumeFlash(): ?array
    {
        if (!isset($_SESSION[self::FLASH_KEY]) || !is_array($_SESSION[self::FLASH_KEY])) {
            return null;
        }
        $flash = $_SESSION[self::FLASH_KEY];
        unset($_SESSION[self::FLASH_KEY]);
        return [
            'ok' => !empty($flash['ok']),
            'message' => (string)($flash['message'] ?? ''),
        ];
    }

    /**
     * Resolve DT for diary day + optional hour (0 = now if today, else 12:00).
     */
    public static function resolveDt(string $date, int $hour): int
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = date('Y-m-d');
        }
        if ($hour >= 1 && $hour <= 23) {
            $ts = strtotime(sprintf('%s %02d:00:00', $date, $hour));
            return $ts !== false ? (int)$ts : time();
        }
        if ($date === date('Y-m-d')) {
            return time();
        }
        $ts = strtotime($date . ' 12:00:00');
        return $ts !== false ? (int)$ts : time();
    }

    /** @param array<string,mixed> $post */
    private static function addFood(array $post): int
    {
        $foodId = (int)($post['food_id'] ?? 0);
        $name = trim((string)($post['food_name'] ?? $post['name'] ?? ''));
        $weight = (float)str_replace(',', '.', (string)($post['weight'] ?? 0));
        if ($weight <= 0) {
            throw new InvalidArgumentException(t('wellness.err.weight_required'));
        }

        $food = $foodId > 0 ? FitFoodsRepository::findOwned($foodId) : null;
        if ($food === null && $name !== '') {
            $food = FitFoodsRepository::findByName($name);
        }
        if ($food === null) {
            $ccals = (int)($post['ccals'] ?? 0);
            $proteins = (float)str_replace(',', '.', (string)($post['proteins'] ?? 0));
            $fats = (float)str_replace(',', '.', (string)($post['fats'] ?? 0));
            $carbs = (float)str_replace(',', '.', (string)($post['carbs'] ?? 0));
            if ($name === '' || $ccals < 1 || ($proteins <= 0 && $fats <= 0 && $carbs <= 0)) {
                throw new InvalidArgumentException(t('wellness.quick.food_need_macros'));
            }
            $foodId = FitFoodsRepository::create([
                'name' => $name,
                'ccals' => $ccals,
                'proteins' => $proteins,
                'fats' => $fats,
                'carbs' => $carbs,
            ]);
        } else {
            $foodId = (int)$food['id'];
        }

        $date = trim((string)($post['date'] ?? date('Y-m-d')));
        $hour = (int)($post['hour'] ?? 0);
        $data = FitFoodlogsRepository::dataFromPost([
            'food_id' => $foodId,
            'weight' => $weight,
            'dt' => self::resolveDt($date, $hour),
        ]);
        return FitFoodlogsRepository::create($data);
    }

    /** @param array<string,mixed> $post */
    private static function addHealth(array $post): int
    {
        $date = trim((string)($post['date'] ?? date('Y-m-d')));
        $hour = (int)($post['hour'] ?? 0);
        $data = HealthLogsRepository::dataFromPost([
            'symptom_id' => (int)($post['symptom_id'] ?? 0),
            'medicine_id' => (int)($post['medicine_id'] ?? 0),
            'symptom_name' => trim((string)($post['symptom_name'] ?? '')),
            'medicine_name' => trim((string)($post['medicine_name'] ?? '')),
            'level' => (int)($post['level'] ?? 0),
            'descr' => (string)($post['descr'] ?? ''),
            'dt' => self::resolveDt($date, $hour),
        ]);
        if ((int)$data['symptom_id'] < 1 && (int)$data['medicine_id'] < 1) {
            throw new InvalidArgumentException(t('wellness.quick.health_need_item'));
        }
        return HealthLogsRepository::create($data);
    }
}
