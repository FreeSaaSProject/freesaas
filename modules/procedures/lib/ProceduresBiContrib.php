<?php
declare(strict_types=1);

/** EventBus bi.datasets / bi.recipes — procedures pack. */
final class ProceduresBiContrib
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
        $list[] = BiDatasetCatalog::define('procedures', 'Procedures', 'base_procedures', 'procedures', [
            'period_field' => 'DTSTART',
            'period_kind' => 'inclusive_dt',
            'filters' => [
                'operator' => 'ID_OPERATOR',
                'admin' => 'ID_ADMIN',
                'good' => 'ID_GOOD',
            ],
            'fields' => [
                ['id' => 'id', 'col' => 'ID', 'type' => 'int', 'agg' => true],
                ['id' => 'dtstart', 'col' => 'DTSTART', 'type' => 'datetime'],
                ['id' => 'summ', 'col' => 'SUMM', 'type' => 'float', 'agg' => true],
                ['id' => 'enabled', 'col' => 'ENABLED', 'type' => 'int'],
                ['id' => 'id_operator', 'col' => 'ID_OPERATOR', 'type' => 'int'],
                ['id' => 'id_admin', 'col' => 'ID_ADMIN', 'type' => 'int'],
                ['id' => 'id_user', 'col' => 'ID_USER', 'type' => 'int'],
                ['id' => 'id_good', 'col' => 'ID_GOOD', 'type' => 'int'],
                ['id' => 'restored', 'col' => 'RESTORED', 'type' => 'int'],
            ],
            'joins' => [
                ['dataset' => 'users_staff', 'left' => 'id_operator', 'right' => 'id'],
                ['dataset' => 'users_staff', 'left' => 'id_admin', 'right' => 'id'],
                ['dataset' => 'products', 'left' => 'id_good', 'right' => 'id'],
            ],
        ]);
        $payload['datasets'] = $list;
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
        $dir = base_path('modules/procedures/recipes/bi');
        foreach (['demo_counts', 'staff_kpi'] as $id) {
            $path = $dir . '/' . $id . '.json';
            if (is_file($path)) {
                $list[] = ['id' => $id, 'module' => 'procedures', 'path' => $path];
            }
        }
        $payload['recipes'] = $list;
        return $payload;
    }
}
