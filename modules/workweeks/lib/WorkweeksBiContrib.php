<?php
declare(strict_types=1);

/** EventBus bi.datasets / bi.derive_steps / bi.recipes — workweeks pack. */
final class WorkweeksBiContrib
{
    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public static function onDatasets($payload)
    {
        if (!is_array($payload) || !class_exists('BiDatasetCatalog', false)) {
            return is_array($payload) ? $payload : ['datasets' => []];
        }
        $list = is_array($payload['datasets'] ?? null) ? $payload['datasets'] : [];
        $list[] = BiDatasetCatalog::define('workweeks', 'Workweeks / shifts', 'base_workweeks', 'workweeks', [
            'period_field' => 'DT',
            'period_kind' => 'inclusive_dt',
            'filters' => [
                'operator' => 'ID_OPERATOR',
            ],
            'fields' => [
                ['id' => 'dt', 'col' => 'DT', 'type' => 'datetime'],
                ['id' => 'id_operator', 'col' => 'ID_OPERATOR', 'type' => 'int'],
                ['id' => 'price_col', 'col' => 'PRICE_COL', 'type' => 'int'],
                ['id' => 'mas_max', 'col' => 'MAS_MAX', 'type' => 'int'],
                ['id' => 'id_table', 'col' => 'ID_TABLE', 'type' => 'int'],
            ],
            'joins' => [
                ['dataset' => 'users_staff', 'left' => 'id_operator', 'right' => 'id'],
            ],
        ]);
        $payload['datasets'] = $list;
        return $payload;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public static function onDeriveSteps($payload)
    {
        if (!is_array($payload)) {
            return ['steps' => []];
        }
        $steps = is_array($payload['steps'] ?? null) ? $payload['steps'] : [];
        $steps[] = [
            'id' => 'expand_shift_lanes',
            'module' => 'workweeks',
            'handler' => [WorkweeksBiShiftLanes::class, 'expandForBi'],
            'legend' => [
                'day_field' => 'dt (workweeks day unix)',
                'price_field' => 'price_col',
                'mode' => 'tickets|cash — cash uses full-day window for non-I+II (oper show_kpi10)',
                'emits' => ['day_start', 'day_label', 'lane', 'is_half', 'window_start', 'window_end', 'smena_label', 'stats_key'],
            ],
        ];
        $payload['steps'] = $steps;
        return $payload;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public static function onRecipes($payload)
    {
        if (!is_array($payload)) {
            return ['recipes' => []];
        }
        $list = is_array($payload['recipes'] ?? null) ? $payload['recipes'] : [];
        $path = base_path('modules/workweeks/recipes/bi/masseur_shifts.json');
        if (is_file($path)) {
            $list[] = ['id' => 'masseur_shifts', 'module' => 'workweeks', 'path' => $path];
        }
        $payload['recipes'] = $list;
        return $payload;
    }
}
