<?php
declare(strict_types=1);

final class FitFoodlogsRepository
{
    /** @return array<string,string> */
    public static function listSortMap(): array
    {
        return [
            'id' => 'l.ID',
            'dt' => 'l.DT',
            'ccals' => 'l.CCALS',
            'weight' => 'l.WEIGHT',
        ];
    }

    /**
     * @param array{q?:string,date?:string,from?:int,to?:int,order?:string,limit?:int,offset?:int} $opts
     * @return array{items:list<array<string,mixed>>,total:int,totals:array<string,float|int>}
     */
    public static function list(array $opts = []): array
    {
        $empty = ['items' => [], 'total' => 0, 'totals' => self::emptyTotals()];
        if (!Database::tableExists('base_fit_foodlogs')) {
            return $empty;
        }
        $userId = WellnessOwner::userId();
        $pdo = Database::pdo();
        $where = ['l.ID_USER = ?'];
        $params = [$userId];

        $date = trim((string)($opts['date'] ?? ''));
        if ($date !== '') {
            [$from, $to] = WellnessOwner::dayRange($date);
            $where[] = 'l.DT BETWEEN ? AND ?';
            $params[] = $from;
            $params[] = $to;
        } else {
            $from = (int)($opts['from'] ?? 0);
            $to = (int)($opts['to'] ?? 0);
            if ($from > 0) {
                $where[] = 'l.DT >= ?';
                $params[] = $from;
            }
            if ($to > 0) {
                $where[] = 'l.DT <= ?';
                $params[] = $to;
            }
        }

        $q = trim((string)($opts['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(f.NAME LIKE ? OR CAST(l.ID AS CHAR) = ?)';
            $params[] = '%' . $q . '%';
            $params[] = $q;
        }

        $sqlWhere = implode(' AND ', $where);
        $join = 'FROM base_fit_foodlogs l LEFT JOIN base_fit_foods f ON f.ID = l.ID_FOOD AND f.ID_USER = l.ID_USER';

        $stc = $pdo->prepare("SELECT COUNT(*) $join WHERE $sqlWhere");
        $stc->execute($params);
        $total = (int)$stc->fetchColumn();

        $stt = $pdo->prepare(
            "SELECT COALESCE(SUM(l.CCALS),0), COALESCE(SUM(l.CARBS),0),
                    COALESCE(SUM(l.PROTEINS),0), COALESCE(SUM(l.FATS),0), COALESCE(SUM(l.WEIGHT),0)
             $join WHERE $sqlWhere"
        );
        $stt->execute($params);
        $sum = $stt->fetch(PDO::FETCH_NUM) ?: [0, 0, 0, 0, 0];
        $totals = [
            'ccals' => (int)round((float)$sum[0]),
            'carbs' => round((float)$sum[1], 2),
            'proteins' => round((float)$sum[2], 2),
            'fats' => round((float)$sum[3], 2),
            'weight' => round((float)$sum[4], 2),
        ];

        $order = trim((string)($opts['order'] ?? ''));
        if ($order === '') {
            $order = 'l.DT DESC, l.ID DESC';
        }
        $limit = max(0, (int)($opts['limit'] ?? 50));
        $offset = max(0, (int)($opts['offset'] ?? 0));
        $sql = "SELECT l.ID AS id, l.ID_USER AS user_id, l.ID_FOOD AS food_id,
                       l.DT_CREATE AS dt_create, l.DT AS dt, l.WEIGHT AS weight,
                       l.CCALS AS ccals, l.CARBS AS carbs, l.PROTEINS AS proteins, l.FATS AS fats,
                       COALESCE(f.NAME, '') AS food_name
                $join WHERE $sqlWhere ORDER BY $order";
        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return [
            'items' => $st->fetchAll(PDO::FETCH_ASSOC) ?: [],
            'total' => $total,
            'totals' => $totals,
        ];
    }

    /** @return array{ccals:int,carbs:float,proteins:float,fats:float,weight:float} */
    private static function emptyTotals(): array
    {
        return ['ccals' => 0, 'carbs' => 0.0, 'proteins' => 0.0, 'fats' => 0.0, 'weight' => 0.0];
    }

    public static function findOwned(int $id): ?array
    {
        if ($id < 1 || !Database::tableExists('base_fit_foodlogs')) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT l.ID AS id, l.ID_USER AS user_id, l.ID_FOOD AS food_id,
                    l.DT_CREATE AS dt_create, l.DT AS dt, l.WEIGHT AS weight,
                    l.CCALS AS ccals, l.CARBS AS carbs, l.PROTEINS AS proteins, l.FATS AS fats,
                    COALESCE(f.NAME, \'\') AS food_name
             FROM base_fit_foodlogs l
             LEFT JOIN base_fit_foods f ON f.ID = l.ID_FOOD AND f.ID_USER = l.ID_USER
             WHERE l.ID = ? AND l.ID_USER = ? LIMIT 1'
        );
        $st->execute([$id, WellnessOwner::userId()]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * @param array<string,mixed> $post
     * @return array<string,mixed>
     */
    public static function dataFromPost(array $post): array
    {
        $foodId = (int)($post['food_id'] ?? $post['id_food'] ?? 0);
        $food = FitFoodsRepository::findOwned($foodId);
        if (!$food) {
            throw new InvalidArgumentException(t('wellness.err.food_required'));
        }
        $weight = max(0.0, (float)str_replace(',', '.', (string)($post['weight'] ?? 0)));
        if ($weight <= 0) {
            throw new InvalidArgumentException(t('wellness.err.weight_required'));
        }
        $factor = $weight / 100.0;
        return [
            'food_id' => $foodId,
            'dt' => WellnessOwner::parseDt($post['dt'] ?? null),
            'weight' => $weight,
            'ccals' => (int)round((int)$food['ccals'] * $factor),
            'carbs' => round((float)$food['carbs'] * $factor, 2),
            'proteins' => round((float)$food['proteins'] * $factor, 2),
            'fats' => round((float)$food['fats'] * $factor, 2),
        ];
    }

    /** @param array<string,mixed> $data */
    public static function create(array $data): int
    {
        return WellnessOwner::insertOwned('base_fit_foodlogs', [
            'ID_FOOD' => (int)$data['food_id'],
            'DT_CREATE' => time(),
            'DT' => (int)$data['dt'],
            'WEIGHT' => $data['weight'],
            'CCALS' => $data['ccals'],
            'CARBS' => $data['carbs'],
            'PROTEINS' => $data['proteins'],
            'FATS' => $data['fats'],
        ]);
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data): void
    {
        if (!self::findOwned($id)) {
            throw new RuntimeException(t('wellness.err.forbidden'));
        }
        $st = Database::pdo()->prepare(
            'UPDATE base_fit_foodlogs
             SET ID_FOOD = ?, DT = ?, WEIGHT = ?, CCALS = ?, CARBS = ?, PROTEINS = ?, FATS = ?
             WHERE ID = ? AND ID_USER = ?'
        );
        $st->execute([
            $data['food_id'],
            $data['dt'],
            $data['weight'],
            $data['ccals'],
            $data['carbs'],
            $data['proteins'],
            $data['fats'],
            $id,
            WellnessOwner::userId(),
        ]);
    }

    /** @param list<int> $ids @return int */
    public static function deleteByIds(array $ids): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $i): bool => $i > 0)));
        if ($ids === [] || !Database::tableExists('base_fit_foodlogs')) {
            return 0;
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $params = $ids;
        $params[] = WellnessOwner::userId();
        $st = Database::pdo()->prepare("DELETE FROM base_fit_foodlogs WHERE ID IN ($ph) AND ID_USER = ?");
        $st->execute($params);
        return $st->rowCount();
    }

    /**
     * Gap threshold between meals (elos63: > 2 hours starts a new meal).
     */
    public const MEAL_GAP_SECONDS = 7200;

    /**
     * Meal label keys in chronological order (translation keys).
     *
     * @return list<string>
     */
    public static function mealLabelKeys(): array
    {
        return [
            'wellness.meal.breakfast',
            'wellness.meal.brunch',
            'wellness.meal.lunch',
            'wellness.meal.afternoon',
            'wellness.meal.dinner',
            'wellness.meal.snacks',
        ];
    }

    /**
     * Group diary rows into floating meals (gap > 2h), morning → evening.
     * Oper show_fit_foodlogs: labels cycle; once at «snacks» further gaps stay in that meal.
     *
     * @param list<array<string,mixed>> $items
     * @return list<array{
     *   key:string,label:string,
     *   items:list<array<string,mixed>>,
     *   totals:array{weight:float,ccals:int,proteins:float,fats:float,carbs:float}
     * }>
     */
    public static function groupByMeals(array $items): array
    {
        if ($items === []) {
            return [];
        }

        usort($items, static function (array $a, array $b): int {
            $da = (int)($a['dt'] ?? 0);
            $db = (int)($b['dt'] ?? 0);
            if ($da !== $db) {
                return $da <=> $db;
            }
            return (int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0);
        });

        $labelKeys = self::mealLabelKeys();
        $maxIdx = count($labelKeys) - 1;
        $meals = [];
        $mealIdx = -1;
        $lastDt = 0;

        foreach ($items as $row) {
            $dt = (int)($row['dt'] ?? 0);
            $openNew = $lastDt === 0 || ($dt - $lastDt) > self::MEAL_GAP_SECONDS;
            if ($openNew && $mealIdx < $maxIdx) {
                $mealIdx++;
                $key = $labelKeys[$mealIdx];
                $meals[] = [
                    'key' => $key,
                    'label' => t($key),
                    'items' => [],
                    'totals' => [
                        'weight' => 0.0,
                        'ccals' => 0,
                        'proteins' => 0.0,
                        'fats' => 0.0,
                        'carbs' => 0.0,
                    ],
                ];
            } elseif ($meals === []) {
                $mealIdx = 0;
                $key = $labelKeys[0];
                $meals[] = [
                    'key' => $key,
                    'label' => t($key),
                    'items' => [],
                    'totals' => [
                        'weight' => 0.0,
                        'ccals' => 0,
                        'proteins' => 0.0,
                        'fats' => 0.0,
                        'carbs' => 0.0,
                    ],
                ];
            }

            $mi = count($meals) - 1;
            $meals[$mi]['items'][] = $row;
            $meals[$mi]['totals']['weight'] += (float)($row['weight'] ?? 0);
            $meals[$mi]['totals']['ccals'] += (int)($row['ccals'] ?? 0);
            $meals[$mi]['totals']['proteins'] += (float)($row['proteins'] ?? 0);
            $meals[$mi]['totals']['fats'] += (float)($row['fats'] ?? 0);
            $meals[$mi]['totals']['carbs'] += (float)($row['carbs'] ?? 0);
            $lastDt = $dt > 0 ? $dt : $lastDt;
        }

        foreach ($meals as &$meal) {
            $meal['totals']['weight'] = round((float)$meal['totals']['weight'], 1);
            $meal['totals']['proteins'] = round((float)$meal['totals']['proteins'], 2);
            $meal['totals']['fats'] = round((float)$meal['totals']['fats'], 2);
            $meal['totals']['carbs'] = round((float)$meal['totals']['carbs'], 2);
        }
        unset($meal);

        return $meals;
    }
}
