<?php
declare(strict_types=1);

/**
 * EventBus bi.datasets / bi.recipes — enrich audio records + script/collection dims + desk family.
 */
final class AudioReportsBiContrib
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

        // Enrich records (same requires_module=audio — mutation, not a conflicting append).
        foreach ($list as $i => $ds) {
            if (!is_array($ds) || ($ds['id'] ?? '') !== 'records') {
                continue;
            }
            $fields = is_array($ds['fields'] ?? null) ? $ds['fields'] : [];
            $have = [];
            foreach ($fields as $f) {
                if (is_array($f) && isset($f['id'])) {
                    $have[(string)$f['id']] = true;
                }
            }
            if (!isset($have['score'])) {
                $fields[] = ['id' => 'score', 'col' => 'SCORE', 'type' => 'float', 'agg' => true];
            }
            if (!isset($have['disabled'])) {
                $fields[] = ['id' => 'disabled', 'col' => 'DISABLED', 'type' => 'int'];
            }
            $ds['fields'] = $fields;
            $list[$i] = $ds;
            break;
        }

        if (Database::tableExists('base_records_scripts')) {
            $list[] = BiDatasetCatalog::define(
                'record_scripts',
                'Audio scripts',
                'base_records_scripts',
                'audio_reports',
                [
                    'period_field' => null,
                    'period_kind' => null,
                    'filters' => [],
                    'fields' => [
                        ['id' => 'id', 'col' => 'ID', 'type' => 'int', 'agg' => true],
                        ['id' => 'name', 'col' => 'NAME', 'type' => 'string'],
                        ['id' => 'pid', 'col' => 'PID', 'type' => 'int'],
                        ['id' => 'finish', 'col' => 'FINISH', 'type' => 'int'],
                        ['id' => 'disabled', 'col' => 'DISABLED', 'type' => 'int'],
                        ['id' => 'id_position', 'col' => 'ID_POSITION', 'type' => 'string'],
                    ],
                    'joins' => [],
                ]
            );
        }

        if (Database::tableExists('base_records_collections')) {
            $joins = [
                ['dataset' => 'users_staff', 'left' => 'id_admin', 'right' => 'id'],
            ];
            if (Database::tableExists('base_records_scripts')) {
                $joins[] = ['dataset' => 'record_scripts', 'left' => 'id_script', 'right' => 'id'];
            }
            $list[] = BiDatasetCatalog::define(
                'record_collections',
                'Audio script collections',
                'base_records_collections',
                'audio_reports',
                [
                    'period_field' => 'DT',
                    'period_kind' => 'inclusive_dt',
                    'filters' => [
                        'admin' => 'ID_ADMIN',
                    ],
                    'fields' => [
                        ['id' => 'id', 'col' => 'ID', 'type' => 'int', 'agg' => true],
                        ['id' => 'id_admin', 'col' => 'ID_ADMIN', 'type' => 'int'],
                        ['id' => 'id_script', 'col' => 'ID_SCRIPT', 'type' => 'int', 'schema_alt' => ['ID_RECORDS_SCRIPT']],
                        ['id' => 'score', 'col' => 'SCORE', 'type' => 'float', 'agg' => true],
                        ['id' => 'dt', 'col' => 'DT', 'type' => 'datetime', 'schema_alt' => ['DT_CREATE', 'DTSTART']],
                    ],
                    'joins' => $joins,
                ]
            );
        }

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
        $path = base_path('modules/audio_reports/recipes/bi/audio_desk_month.json');
        if (is_file($path)) {
            $list[] = ['id' => 'audio_desk_month', 'module' => 'audio_reports', 'path' => $path];
        }
        $payload['recipes'] = $list;
        return $payload;
    }
}
