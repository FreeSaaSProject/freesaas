<?php
declare(strict_types=1);

/** EventBus bi.datasets — dictionary + key dimensions for invite/hit analytics. */
final class AudioDictsBiContrib
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
            'record_dictionaries',
            'Audio dictionaries',
            'base_records_dictonarys',
            'audio_dicts',
            [
                'period_field' => null,
                'period_kind' => null,
                'filters' => [],
                'fields' => [
                    ['id' => 'id', 'col' => 'ID', 'type' => 'int'],
                    ['id' => 'name', 'col' => 'NAME', 'type' => 'string'],
                    ['id' => 'tracking_code', 'col' => 'TRACKING_CODE', 'type' => 'string'],
                    ['id' => 'disabled', 'col' => 'DISABLED', 'type' => 'int'],
                ],
                'joins' => [],
            ]
        );
        $list[] = BiDatasetCatalog::define(
            'record_keys',
            'Audio dictionary keys',
            'base_records_keys',
            'audio_dicts',
            [
                'period_field' => null,
                'period_kind' => null,
                'filters' => [],
                'fields' => [
                    ['id' => 'id', 'col' => 'ID', 'type' => 'int'],
                    ['id' => 'name', 'col' => 'NAME', 'type' => 'string'],
                    ['id' => 'id_dict', 'col' => 'ID_DICTONARY', 'type' => 'int'],
                    ['id' => 'disabled', 'col' => 'DISABLED', 'type' => 'int'],
                ],
                'joins' => [
                    ['dataset' => 'record_dictionaries', 'left' => 'id_dict', 'right' => 'id'],
                ],
            ]
        );
        $payload['datasets'] = $list;
        return $payload;
    }
}
