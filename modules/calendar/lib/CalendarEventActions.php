<?php
declare(strict_types=1);

/**
 * Side actions for the event sheet. Product modules append via calendar.event.actions.
 */
final class CalendarEventActions
{
    /**
     * @param array<string,mixed> $row
     * @param list<array<string,mixed>> $linkedProcedures
     * @return list<array<string,mixed>>
     */
    public static function collect(
        array $row,
        int $id,
        bool $isNew,
        bool $canModify,
        bool $canMakeProcedures,
        array $linkedProcedures
    ): array {
        $actions = [];
        $clientId = (int)($row['id_user'] ?? 0);

        if ($canMakeProcedures) {
            $actions[] = [
                'id' => 'start',
                'icon' => '▶',
                'label' => t('calendar.event.act_start'),
                'submit_action' => 'create_procedures',
                'order' => 10,
            ];
        }
        if ($clientId > 0 && Auth::can('clients.view')) {
            $actions[] = [
                'id' => 'client',
                'icon' => '👤',
                'label' => t('calendar.event.act_client'),
                'href' => '#event-client-sec',
                'order' => 20,
            ];
            $actions[] = [
                'id' => 'history',
                'icon' => '🕘',
                'label' => t('calendar.event.act_history'),
                'href' => '/clients/' . $clientId,
                'order' => 80,
            ];
        }

        $payload = EventBus::emit('calendar.event.actions', [
            'row' => $row,
            'id' => $id,
            'is_new' => $isNew,
            'can_modify' => $canModify,
            'actions' => $actions,
        ]);
        $out = is_array($payload) && is_array($payload['actions'] ?? null) ? $payload['actions'] : $actions;
        $seen = [];
        $clean = [];
        foreach ($out as $act) {
            if (!is_array($act)) {
                continue;
            }
            $aid = trim((string)($act['id'] ?? ''));
            if ($aid === '' || isset($seen[$aid])) {
                continue;
            }
            $seen[$aid] = true;
            $clean[] = $act;
        }
        usort($clean, static function (array $a, array $b): int {
            return ((int)($a['order'] ?? 0)) <=> ((int)($b['order'] ?? 0));
        });
        return $clean;
    }
}
