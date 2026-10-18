<?php
declare(strict_types=1);

/** EventBus bi.datasets — products dimension. */
final class CatalogBiContrib
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
        $list[] = BiDatasetCatalog::define('products', 'Products / services', 'base_products', 'catalog', [
            'period_field' => null,
            'period_kind' => null,
            'filters' => [],
            'fields' => [
                ['id' => 'id', 'col' => 'id', 'type' => 'int'],
                ['id' => 'type_id', 'col' => 'type_id', 'type' => 'int', 'schema_alt' => ['TYPE']],
                ['id' => 'duration', 'col' => 'time_sec', 'type' => 'int', 'schema_alt' => ['time_net', 'TIME_NET', 'TIME']],
            ],
            'joins' => [],
        ]);
        $payload['datasets'] = $list;
        return $payload;
    }
}
