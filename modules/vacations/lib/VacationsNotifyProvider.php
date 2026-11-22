<?php
declare(strict_types=1);

/**
 * Vacations as a `notify` producer: "leave starts in N days" reminders (daily cron).
 * Active only while the `notify` module is enabled; VacationsGroupNotify keeps the legacy bot path otherwise.
 */
final class VacationsNotifyProvider
{
    public static function active(): bool
    {
        return ModuleManager::isEnabled('notify');
    }

    public static function register(): void
    {
        EventBus::on('notify.catalog', [self::class, 'onCatalog']);
    }

    /**
     * @param mixed $payload
     * @return array<string,mixed>
     */
    public static function onCatalog($payload): array
    {
        $payload = is_array($payload) ? $payload : [];
        $payload['events'][] = [
            'id' => 'vacations.remind',
            'module' => 'vacations',
            'label' => 'vacations.notify.event.remind',
            'entity' => 'vacation',
            'fields' => [
                ['id' => 'days', 'type' => 'number', 'op' => ['eq', 'lte', 'gte'], 'label' => 'vacations.notify.field.days'],
            ],
            'vars' => ['acronym', 'name', 'start', 'end', 'days'],
            'subjects' => ['operator'],
            'anchor' => 'dt_start',
            'outcomes' => [],
            'default_template' => 'vacations.remind.message',
        ];
        return $payload;
    }

    /**
     * @param array<string,mixed> $row VacationsRepository::listStartingBetween() row
     * @return int queued deliveries
     */
    public static function emit(array $row, int $days): int
    {
        $id = (int)($row['id'] ?? 0);
        $userId = (int)($row['user_id'] ?? 0);
        $ctx = VacationsGroupNotify::vars($row, $days);
        $ctx['days'] = $days;
        $ctx['user_id'] = $userId;
        $ctx['dt_start'] = (int)($row['dt_start'] ?? 0);
        try {
            $res = EventBus::emit('notify.event', [
                'event' => 'vacations.remind',
                'ctx' => $ctx,
                'subjects' => $userId > 0 ? ['operator' => ['user_id' => $userId]] : [],
                'entity' => 'vacation',
                'entity_id' => $id,
                'anchor_ts' => (int)($row['dt_start'] ?? 0),
                'dedupe_suffix' => 'd' . $days,
            ]);
        } catch (Throwable $e) {
            error_log('[vacations.notify] ' . $e->getMessage());
            return 0;
        }
        $n = 0;
        foreach ((array)($res['results'] ?? []) as $r) {
            if (is_array($r) && ($r['status'] ?? '') === 'queued') {
                $n++;
            }
        }
        return $n;
    }
}
