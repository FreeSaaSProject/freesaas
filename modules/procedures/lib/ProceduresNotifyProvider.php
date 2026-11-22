<?php
declare(strict_types=1);

/**
 * Procedures as a `notify` producer. Emits EventBus `procedures.created|updated|cancelled`
 * (always) and `notify.event` when the notify module is enabled; ProcedureBotNotify stays the
 * fallback while notify is off.
 */
final class ProceduresNotifyProvider
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
        $fields = [
            ['id' => 'type', 'type' => 'enum', 'op' => ['eq', 'neq', 'in', 'nin'], 'options_from' => 'procedures.types', 'label' => 'procedures.notify.field.type'],
            ['id' => 'product_category', 'type' => 'enum', 'op' => ['eq', 'neq', 'in', 'nin'], 'options_from' => 'catalog.categories', 'label' => 'procedures.notify.field.product_category'],
            ['id' => 'operator_hidden', 'type' => 'bool', 'op' => ['eq'], 'label' => 'procedures.notify.field.operator_hidden'],
            ['id' => 'is_today', 'type' => 'bool', 'op' => ['eq'], 'label' => 'procedures.notify.field.is_today'],
        ];
        $base = [
            'module' => 'procedures',
            'entity' => 'procedure',
            'fields' => $fields,
            'vars' => ['id', 'when', 'date', 'type_name', 'client_name', 'operator_name'],
            'subjects' => ['operator', 'client'],
            'anchor' => 'dtstart',
            'outcomes' => [],
        ];
        $payload['events'][] = $base + [
            'id' => 'procedures.created',
            'label' => 'procedures.notify.event.created',
            'default_template' => 'procedures.bot.notify_new',
        ];
        $payload['events'][] = $base + [
            'id' => 'procedures.updated',
            'label' => 'procedures.notify.event.updated',
            'default_template' => 'procedures.bot.notify_update',
        ];
        $payload['events'][] = $base + [
            'id' => 'procedures.cancelled',
            'label' => 'procedures.notify.event.cancelled',
            'default_template' => 'procedures.bot.notify_cancel',
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
        if (($payload['key'] ?? '') === 'procedures.types') {
            foreach (ProcedureTypesRepository::names() as $id => $name) {
                $payload['options'][(string)$id] = (string)$name;
            }
        }
        return $payload;
    }

    /**
     * @param array<string,mixed> $data procedure fields (id_operator, dtstart, type, id_good, id_user, …)
     * @return array<string,mixed> notify.event payload without `event`
     */
    public static function context(int $id, array $data): array
    {
        $operatorId = (int)($data['id_operator'] ?? 0);
        $clientId = (int)($data['id_user'] ?? 0);
        $dtStart = (int)($data['dtstart'] ?? 0);
        $typeId = (int)($data['type'] ?? 0);
        $goodId = (int)($data['id_good'] ?? 0);

        $category = '';
        if ($goodId > 0 && class_exists('ProductsRepository', false)) {
            $p = ProductsRepository::find($goodId);
            if (is_array($p) && (int)($p['category'] ?? 0) > 0) {
                $category = (string)(int)$p['category'];
            }
        }
        $hidden = 0;
        $operatorName = '';
        if ($operatorId > 0 && class_exists('UsersRepository', false)) {
            $u = UsersRepository::find($operatorId);
            if (is_array($u)) {
                $hidden = (int)($u['IS_HIDDEN'] ?? 0) === 1 ? 1 : 0;
                $operatorName = trim((string)($u['name'] ?? ''));
            }
        }
        $clientName = '';
        if ($clientId > 0 && class_exists('ClientsRepository', false)) {
            $c = ClientsRepository::find($clientId);
            $clientName = is_array($c) ? trim((string)($c['name'] ?? '')) : '';
        }
        $typeName = $typeId > 0 ? (ProcedureTypesRepository::names()[$typeId] ?? '') : '';
        $todayStart = (int)mktime(0, 0, 0, (int)date('n'), (int)date('j'), (int)date('Y'));
        $isToday = $dtStart >= $todayStart && $dtStart < $todayStart + 86400;

        $subjects = [];
        if ($operatorId > 0) {
            $subjects['operator'] = ['user_id' => $operatorId];
        }
        if ($clientId > 0) {
            $subjects['client'] = ['client_id' => $clientId];
        }
        return [
            'ctx' => [
                'id' => $id,
                'when' => $dtStart > 0 ? date('H:i', $dtStart) : '',
                'date' => $dtStart > 0 ? date('d.m.Y', $dtStart) : '',
                'dtstart' => $dtStart,
                'type' => $typeId > 0 ? (string)$typeId : '',
                'type_name' => (string)$typeName,
                'product_category' => $category,
                'operator_id' => $operatorId,
                'operator_name' => $operatorName,
                'operator_hidden' => $hidden,
                'client_name' => $clientName,
                'is_today' => $isToday ? 1 : 0,
            ],
            'subjects' => $subjects,
            'entity' => 'procedure',
            'entity_id' => $id,
            'client_id' => $clientId,
            'anchor_ts' => $dtStart,
        ];
    }

    /**
     * @param string $event procedures.created|updated|cancelled
     * @param array<string,mixed> $data
     * @return bool true when notify owns delivery (caller must skip the legacy bot path)
     */
    public static function dispatch(string $event, int $id, array $data): bool
    {
        if ($id < 1) {
            return false;
        }
        EventBus::emit($event, ['id' => $id, 'data' => $data]);
        if (!self::active()) {
            return false;
        }
        $payload = self::context($id, $data);
        $payload['event'] = $event;
        $payload['dedupe_suffix'] = $event === 'procedures.updated'
            ? substr(md5((int)($data['dtstart'] ?? 0) . '|' . (int)($data['id_operator'] ?? 0) . '|' . (int)($data['type'] ?? 0)), 0, 10)
            : '';
        try {
            EventBus::emit('notify.event', $payload);
        } catch (Throwable $e) {
            error_log('[procedures.notify] ' . $e->getMessage());
        }
        return true;
    }
}
