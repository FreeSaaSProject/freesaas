<?php
declare(strict_types=1);

/** EventBus bi.datasets — platform staff dimension. */
final class CoreBiContrib
{
    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public static function onDatasets($payload)
    {
        if (!is_array($payload)) {
            return ['datasets' => []];
        }
        if (!class_exists('BiDatasetCatalog', false)) {
            return $payload;
        }
        $list = is_array($payload['datasets'] ?? null) ? $payload['datasets'] : [];
        $list[] = BiDatasetCatalog::define('users_staff', 'Staff users', 'base_users', 'core', [
            'period_field' => null,
            'period_kind' => null,
            'filters' => [],
            'fields' => [
                ['id' => 'id', 'col' => 'id', 'type' => 'int'],
                ['id' => 'name', 'col' => 'name', 'type' => 'string'],
                ['id' => 'login', 'col' => 'login', 'type' => 'string'],
                ['id' => 'enabled', 'col' => 'enabled', 'type' => 'string'],
                ['id' => 'id_status', 'col' => 'ID_STATUS', 'type' => 'int', 'schema_alt' => ['name_enabled']],
                ['id' => 'is_hidden', 'col' => 'IS_HIDDEN', 'type' => 'int'],
                ['id' => 'assist_team', 'col' => 'ASSIST_TEAM', 'type' => 'int'],
            ],
            'joins' => [],
        ]);
        $payload['datasets'] = $list;
        return $payload;
    }
}
