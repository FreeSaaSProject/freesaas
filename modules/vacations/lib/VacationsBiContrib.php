<?php
declare(strict_types=1);

/** EventBus bi.datasets — vacations. */
final class VacationsBiContrib
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
        $list[] = BiDatasetCatalog::define('vacations', 'Vacations', 'base_vacations', 'vacations', [
            'period_field' => 'DT_START',
            'period_end_field' => 'DT_END',
            'period_kind' => 'overlap_dt',
            'filters' => [
                'operator' => 'ID_USER',
            ],
            'fields' => [
                ['id' => 'id_user', 'col' => 'ID_USER', 'type' => 'int'],
                ['id' => 'id_type', 'col' => 'ID_TYPE', 'type' => 'int'],
                ['id' => 'dt_start', 'col' => 'DT_START', 'type' => 'datetime'],
                ['id' => 'dt_end', 'col' => 'DT_END', 'type' => 'datetime'],
            ],
            'joins' => [
                ['dataset' => 'users_staff', 'left' => 'id_user', 'right' => 'id'],
            ],
        ]);
        $payload['datasets'] = $list;
        return $payload;
    }
}
