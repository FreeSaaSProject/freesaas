<?php
declare(strict_types=1);

/** EventBus bi.datasets / bi.recipes — guests + admin conversion. */
final class TerminalsBiContrib
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
        $list[] = BiDatasetCatalog::define('guests', 'Guests (fullstats)', 'base_fullstats', 'terminals', [
            'period_field' => 'DTSTART',
            'period_kind' => 'half_open',
            'filters' => [],
            'fields' => [
                ['id' => 'id', 'col' => 'ID', 'type' => 'int', 'agg' => true],
                ['id' => 'dtstart', 'col' => 'DTSTART', 'type' => 'datetime'],
            ],
            'joins' => [],
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
        $path = base_path('modules/terminals/recipes/bi/admin_conversion.json');
        if (is_file($path)) {
            $list[] = ['id' => 'admin_conversion', 'module' => 'terminals', 'path' => $path];
        }
        $payload['recipes'] = $list;
        return $payload;
    }
}
