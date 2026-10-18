<?php
declare(strict_types=1);

/** EventBus bi.datasets — base audio records (enriched further by audio_reports). */
final class AudioBiContrib
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
        $list[] = BiDatasetCatalog::define('records', 'Audio records', 'base_records', 'audio', [
            'period_field' => 'DT_RECORDED',
            'period_kind' => 'inclusive_dt',
            'filters' => [
                'admin' => 'ID_ADMIN',
            ],
            'fields' => [
                ['id' => 'id', 'col' => 'ID', 'type' => 'int', 'agg' => true],
                ['id' => 'playtime', 'col' => 'PLAYTIME', 'type' => 'float', 'agg' => true],
                ['id' => 'id_admin', 'col' => 'ID_ADMIN', 'type' => 'int'],
                ['id' => 'dt_recorded', 'col' => 'DT_RECORDED', 'type' => 'datetime'],
            ],
            'joins' => [
                ['dataset' => 'users_staff', 'left' => 'id_admin', 'right' => 'id'],
            ],
        ]);
        $payload['datasets'] = $list;
        return $payload;
    }
}
