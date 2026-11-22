<?php
declare(strict_types=1);

/**
 * Backups as a `notify` producer (failed / succeeded / stale / test). Used only while the
 * `notify` module is enabled; BackupNotify::sendBots remains the fallback otherwise.
 */
final class BackupsNotifyProvider
{
    public static function active(): bool
    {
        return ModuleManager::isEnabled('notify');
    }

    public static function register(): void
    {
        EventBus::on('notify.catalog', [self::class, 'onCatalog']);
        EventBus::on('notify.options', [self::class, 'onOptions']);
    }

    /**
     * @param mixed $payload
     * @return array<string,mixed>
     */
    public static function onCatalog($payload): array
    {
        $payload = is_array($payload) ? $payload : [];
        $kind = ['id' => 'kind', 'type' => 'enum', 'op' => ['eq', 'neq'], 'options_from' => 'backups.kinds', 'label' => 'backups.notify.field.kind'];
        $base = ['module' => 'backups', 'entity' => 'backup_run', 'subjects' => [], 'anchor' => '', 'outcomes' => []];
        $payload['events'][] = $base + [
            'id' => 'backups.failed',
            'label' => 'backups.notify.event.failed',
            'fields' => [$kind],
            'vars' => ['id', 'kind', 'error', 'phase', 'duration', 'bytes'],
            'default_template' => 'backups.notify.fail',
        ];
        $payload['events'][] = $base + [
            'id' => 'backups.succeeded',
            'label' => 'backups.notify.event.succeeded',
            'fields' => [$kind],
            'vars' => ['id', 'kind', 'bytes', 'duration', 'remote'],
            'default_template' => 'backups.notify.success',
        ];
        $payload['events'][] = $base + [
            'id' => 'backups.stale',
            'label' => 'backups.notify.event.stale',
            'fields' => [],
            'vars' => ['hours'],
            'default_template' => 'backups.notify.stale',
        ];
        $payload['events'][] = $base + [
            'id' => 'backups.test',
            'label' => 'backups.notify.event.test',
            'fields' => [],
            'vars' => ['at'],
            'default_template' => 'backups.notify.test',
        ];
        return $payload;
    }

    /**
     * @param mixed $payload
     * @return array<string,mixed>
     */
    public static function onOptions($payload): array
    {
        $payload = is_array($payload) ? $payload : [];
        if (($payload['key'] ?? '') === 'backups.kinds') {
            $payload['options'] = ($payload['options'] ?? []) + ['full' => 'full', 'incr' => 'incr', 'cdc' => 'cdc'];
        }
        return $payload;
    }

    /**
     * @param array<string,mixed> $ctx
     * @return int number of queued deliveries
     */
    public static function emit(string $event, array $ctx, int $entityId = 0, string $suffix = ''): int
    {
        try {
            $res = EventBus::emit('notify.event', [
                'event' => $event,
                'ctx' => $ctx,
                'subjects' => [],
                'entity' => 'backup_run',
                'entity_id' => $entityId,
                'dedupe_suffix' => $suffix,
            ]);
        } catch (Throwable $e) {
            error_log('[backups.notify] ' . $e->getMessage());
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
