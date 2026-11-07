<?php
declare(strict_types=1);

/** EventBus bi.datasets — oral speech scores bound to procedure staff. */
final class PollSpeechBiContrib
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
        if (!Database::tableExists('base_poll_invites')
            || !Database::columnExists('base_poll_invites', 'SCORE_STAFF')
        ) {
            return $payload;
        }
        $list = is_array($payload['datasets'] ?? null) ? $payload['datasets'] : [];
        $list[] = BiDatasetCatalog::define(
            'poll_speech_scores',
            'Oral speech scores',
            'base_poll_invites',
            'poll_speech',
            [
                'period_field' => 'DT_COMPLETED',
                'period_kind' => 'inclusive_dt',
                'filters' => [
                    'operator' => 'ID_PROC_OPERATOR',
                    'admin' => 'ID_PROC_ADMIN',
                    'poll' => 'ID_POLL',
                ],
                'fields' => [
                    ['id' => 'id', 'col' => 'ID', 'type' => 'int', 'agg' => true],
                    ['id' => 'dt_completed', 'col' => 'DT_COMPLETED', 'type' => 'datetime'],
                    ['id' => 'dt_create', 'col' => 'DT_CREATE', 'type' => 'datetime'],
                    ['id' => 'id_poll', 'col' => 'ID_POLL', 'type' => 'int'],
                    ['id' => 'id_proc', 'col' => 'ID_PROC', 'type' => 'int'],
                    ['id' => 'id_user', 'col' => 'ID_USER', 'type' => 'int'],
                    ['id' => 'id_operator', 'col' => 'ID_PROC_OPERATOR', 'type' => 'int'],
                    ['id' => 'id_admin', 'col' => 'ID_PROC_ADMIN', 'type' => 'int'],
                    ['id' => 'score_staff', 'col' => 'SCORE_STAFF', 'type' => 'int', 'agg' => true],
                    ['id' => 'score_service', 'col' => 'SCORE_SERVICE', 'type' => 'int', 'agg' => true],
                ],
                'joins' => [
                    ['dataset' => 'users_staff', 'left' => 'id_operator', 'right' => 'id'],
                    ['dataset' => 'users_staff', 'left' => 'id_admin', 'right' => 'id'],
                    ['dataset' => 'procedures', 'left' => 'id_proc', 'right' => 'id'],
                ],
            ]
        );
        $payload['datasets'] = $list;
        return $payload;
    }
}
