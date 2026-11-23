<?php
declare(strict_types=1);

final class AcquisitionBiContrib
{
    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public static function onDatasets($payload)
    {
        if (!is_array($payload) || !class_exists('BiDatasetCatalog', false)) {
            return is_array($payload) ? $payload : ['datasets' => []];
        }
        if (!CallsRepository::tableReady()) {
            return $payload;
        }
        $list = is_array($payload['datasets'] ?? null) ? $payload['datasets'] : [];
        $list[] = BiDatasetCatalog::define('acquisition_calls', 'Acquisition calls', 'base_calls', 'acquisition', [
            'period_field' => 'DT_CALL',
            'period_kind' => 'inclusive_dt',
            'filters' => [
                'admin' => 'ID_ADMIN',
                'result' => 'ID_RESULT',
            ],
            'fields' => [
                ['id' => 'id', 'col' => 'ID', 'type' => 'int', 'agg' => true],
                ['id' => 'dt_call', 'col' => 'DT_CALL', 'type' => 'datetime'],
                ['id' => 'result_id', 'col' => 'ID_RESULT', 'type' => 'int'],
                ['id' => 'client_kind', 'col' => 'ID_CLIENT_TYPE', 'type' => 'int'],
                ['id' => 'admin_id', 'col' => 'ID_ADMIN', 'type' => 'int'],
                ['id' => 'client_id', 'col' => 'ID_CLIENT', 'type' => 'int'],
                ['id' => 'channel', 'col' => 'CHANNEL', 'type' => 'string'],
                ['id' => 'phone', 'col' => 'PHONE', 'type' => 'string'],
            ],
            'joins' => [
                ['dataset' => 'users_staff', 'left' => 'admin_id', 'right' => 'id'],
            ],
        ]);
        if (PromoSourcesRepository::tableReady()) {
            $list[] = BiDatasetCatalog::define(
                'promo_sources',
                'Promo sources',
                'base_promo_sources',
                'acquisition',
                [
                    'period_field' => null,
                    'period_kind' => null,
                    'filters' => [],
                    'fields' => [
                        ['id' => 'id', 'col' => 'ID', 'type' => 'int'],
                        ['id' => 'name', 'col' => 'NAME', 'type' => 'string'],
                        ['id' => 'enabled', 'col' => 'ENABLED', 'type' => 'int'],
                    ],
                    'joins' => [],
                ]
            );
        }
        if (Database::tableExists('base_account_moves')
            && Database::columnExists('base_account_moves', 'ID_SOURCE')
            && ModuleManager::isEnabled('ledger')
        ) {
            $list[] = BiDatasetCatalog::define(
                'account_moves_src',
                'Account moves (with source)',
                'base_account_moves',
                'acquisition',
                [
                    'period_field' => 'DT',
                    'period_kind' => 'inclusive_dt',
                    'filters' => [
                        'source' => 'ID_SOURCE',
                    ],
                    'fields' => [
                        ['id' => 'id', 'col' => 'ID', 'type' => 'int', 'agg' => true],
                        ['id' => 'dt', 'col' => 'DT', 'type' => 'datetime'],
                        ['id' => 'summ', 'col' => 'SUMM', 'type' => 'float', 'agg' => true],
                        ['id' => 'source_id', 'col' => 'ID_SOURCE', 'type' => 'int'],
                        ['id' => 'deleted', 'col' => 'DELETED', 'type' => 'int'],
                        ['id' => 'type_id', 'col' => 'ID_TYPE', 'type' => 'int'],
                    ],
                    'joins' => [
                        ['dataset' => 'promo_sources', 'left' => 'source_id', 'right' => 'id'],
                    ],
                ]
            );
        }
        $payload['datasets'] = $list;
        return $payload;
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public static function onRecipes($payload)
    {
        if (!is_array($payload)) {
            return ['recipes' => []];
        }
        $list = is_array($payload['recipes'] ?? null) ? $payload['recipes'] : [];
        $dir = base_path('modules/acquisition/recipes/bi');
        foreach (['admin_funnel', 'romi_by_source'] as $id) {
            $path = $dir . '/' . $id . '.json';
            if (is_file($path)) {
                $list[] = ['id' => $id, 'module' => 'acquisition', 'path' => $path];
            }
        }
        $payload['recipes'] = $list;
        return $payload;
    }
}
