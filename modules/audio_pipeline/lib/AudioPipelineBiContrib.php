<?php
declare(strict_types=1);

/** EventBus bi.datasets — ASR key-hit instances (tracking / invites). */
final class AudioPipelineBiContrib
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
        $list[] = BiDatasetCatalog::define(
            'record_instances',
            'Audio key hits',
            'base_records_instances',
            'audio_pipeline',
            [
                'period_field' => 'DT_START',
                'period_kind' => 'inclusive_dt',
                'filters' => [
                    'admin' => 'ID_ADMIN',
                ],
                'fields' => [
                    ['id' => 'id', 'col' => 'ID', 'type' => 'int', 'agg' => true],
                    ['id' => 'dt_start', 'col' => 'DT_START', 'type' => 'datetime'],
                    ['id' => 'id_admin', 'col' => 'ID_ADMIN', 'type' => 'int'],
                    ['id' => 'id_record', 'col' => 'ID_RECORD', 'type' => 'int'],
                    ['id' => 'id_key', 'col' => 'ID_KEY', 'type' => 'int'],
                ],
                'joins' => [
                    ['dataset' => 'record_keys', 'left' => 'id_key', 'right' => 'id'],
                    ['dataset' => 'users_staff', 'left' => 'id_admin', 'right' => 'id'],
                ],
            ]
        );
        $payload['datasets'] = $list;
        return $payload;
    }
}
