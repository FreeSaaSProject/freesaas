<?php
declare(strict_types=1);

/** EventBus bi.datasets — poll invite meta. */
final class PollInvitesBiContrib
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
        $list[] = BiDatasetCatalog::define('poll_invites', 'Poll invites', 'base_poll_invites', 'poll_invites', [
            'period_field' => 'DT_CREATE',
            'period_kind' => 'inclusive_dt',
            'filters' => [],
            'fields' => [
                ['id' => 'id', 'col' => 'ID', 'type' => 'int', 'agg' => true],
                ['id' => 'dt_create', 'col' => 'DT_CREATE', 'type' => 'datetime'],
                ['id' => 'status', 'col' => 'STATUS', 'type' => 'int'],
            ],
            'joins' => [],
        ]);
        $payload['datasets'] = $list;
        return $payload;
    }
}
