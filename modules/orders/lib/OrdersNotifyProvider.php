<?php
declare(strict_types=1);

/**
 * Orders → notify.catalog / notify.event / notify.scan.
 * When notify is enabled, OrdersMaxNotify delegates here instead of hard-coding a Max group.
 */
final class OrdersNotifyProvider
{
    /** @var bool suppress orders.updated during cancel→onCancelled path */
    private static $quiet = false;

    public static function active(): bool
    {
        return ModuleManager::isEnabled('notify')
            && class_exists('NotifyEngine', false)
            && Database::tableExists('base_notify_rules');
    }

    public static function setQuiet(bool $quiet): void
    {
        self::$quiet = $quiet;
    }

    /** Alias used by OrdersMaxNotify / OrdersRepository. */
    public static function emitEvent(string $event, int $orderId, array $extra = []): void
    {
        self::emit($event, $orderId, $extra);
    }

    /**
     * Emit orders.updated (or completed) when staff/date/status change meaningfully.
     *
     * @param array<string,mixed> $prev
     * @param array<string,mixed> $now
     */
    public static function onChanged(array $prev, array $now): void
    {
        if (self::$quiet || !self::active()) {
            return;
        }
        $id = (int)($now['id'] ?? 0);
        if ($id < 1) {
            return;
        }
        $prevStatus = (string)($prev['status'] ?? '');
        $nowStatus = (string)($now['status'] ?? '');
        if ($nowStatus === 'complete' && $prevStatus !== 'complete') {
            self::emit('orders.completed', $id, ['closes_tasks' => true]);
            return;
        }
        if ($nowStatus === 'userCancelPay' || $nowStatus === 'deletedByAdmin') {
            // cancel path emits orders.cancelled separately
            return;
        }
        $changed = false;
        foreach (['dt_event', 'id_cosmetolog', 'status', 'summ_real'] as $k) {
            if ((string)($prev[$k] ?? '') !== (string)($now[$k] ?? '')) {
                $changed = true;
                break;
            }
        }
        if ($changed) {
            self::emit('orders.updated', $id);
        }
    }

    public static function register(): void
    {
        EventBus::on('notify.catalog', static function ($payload) {
            if (!is_array($payload)) {
                $payload = ['events' => []];
            }
            $events = is_array($payload['events'] ?? null) ? $payload['events'] : [];
            foreach (self::catalogEvents() as $def) {
                $events[] = $def;
            }
            $payload['events'] = $events;
            return $payload;
        });

        EventBus::on('notify.options', static function ($payload) {
            if (!is_array($payload) || (string)($payload['key'] ?? '') !== 'orders.sources') {
                return $payload;
            }
            $opts = [];
            foreach (OrdersConfig::sources() as $s) {
                $opts[$s] = $s;
            }
            $payload['options'] = $opts;
            return $payload;
        });

        EventBus::on('notify.scan', static function ($payload) {
            if (!is_array($payload)) {
                return $payload;
            }
            $events = (array)($payload['events'] ?? []);
            $want = array_intersect($events, ['orders.confirm', 'orders.created']);
            if ($want === []) {
                return $payload;
            }
            $from = (int)($payload['from'] ?? 0);
            $to = (int)($payload['to'] ?? 0);
            if ($from < 1 || $to < $from) {
                return $payload;
            }
            $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
            foreach (self::scanWindow($from, $to) as $item) {
                $items[] = $item;
            }
            $payload['items'] = $items;
            return $payload;
        });

        EventBus::on('notify.task.resolved', static function ($payload) {
            if (!is_array($payload)) {
                return $payload;
            }
            if ((string)($payload['entity'] ?? '') !== 'order') {
                return $payload;
            }
            if ((string)($payload['outcome'] ?? '') !== 'client_cancelled') {
                return $payload;
            }
            $orderId = (int)($payload['entity_id'] ?? 0);
            $clientId = (int)($payload['client_id'] ?? 0);
            if ($orderId < 1) {
                return $payload;
            }
            try {
                OrdersRepository::cancelForClient($orderId, $clientId, 'notify_confirm');
            } catch (Throwable $e) {
                error_log('[orders.notify] cancel: ' . $e->getMessage());
            }
            return $payload;
        });
    }

    /** @return list<array<string,mixed>> */
    public static function catalogEvents(): array
    {
        $fields = [
            ['id' => 'source', 'type' => 'enum', 'op' => ['eq', 'in', 'neq', 'nin'], 'options_from' => 'orders.sources', 'label' => 'orders.notify.field.source'],
            ['id' => 'product_category', 'type' => 'number', 'op' => ['eq', 'in', 'neq'], 'label' => 'orders.notify.field.product_categories'],
            ['id' => 'procedure_type', 'type' => 'number', 'op' => ['eq', 'in', 'neq'], 'label' => 'orders.notify.field.procedure_types'],
            ['id' => 'operator_id', 'type' => 'number', 'op' => ['eq', 'neq'], 'label' => 'orders.notify.field.operator_id'],
            ['id' => 'operator_hidden', 'type' => 'bool', 'op' => ['eq'], 'label' => 'orders.notify.field.operator_hidden'],
            ['id' => 'lead_hours', 'type' => 'number', 'op' => ['gt', 'gte', 'lt', 'lte'], 'label' => 'orders.notify.field.lead_hours'],
            ['id' => 'sum', 'type' => 'number', 'op' => ['gt', 'gte', 'lt', 'lte'], 'label' => 'orders.notify.field.sum'],
            ['id' => 'cancelled_by', 'type' => 'enum', 'op' => ['eq', 'neq', 'in', 'nin'], 'options' => ['client' => 'client', 'staff' => 'staff'], 'label' => 'orders.notify.field.cancelled_by'],
        ];
        $vars = ['order_id', 'client_name', 'phone', 'service', 'source', 'when', 'master', 'sum', 'reason', 'cancelled_by'];
        $subjects = ['client', 'operator'];
        $outcomes = ['confirmed', 'no_answer', 'client_cancelled'];
        $base = [
            'module' => 'orders',
            'entity' => 'order',
            'fields' => $fields,
            'vars' => $vars,
            'subjects' => $subjects,
            'anchor' => 'dt_event',
            'outcomes' => $outcomes,
        ];
        return [
            $base + ['id' => 'orders.created', 'label' => 'orders.notify.event.created', 'default_template' => 'orders.notify.tpl.created'],
            $base + ['id' => 'orders.cancelled', 'label' => 'orders.notify.event.cancelled', 'default_template' => 'orders.notify.tpl.cancelled'],
            $base + ['id' => 'orders.completed', 'label' => 'orders.notify.event.completed', 'default_template' => 'orders.notify.tpl.completed'],
            $base + ['id' => 'orders.updated', 'label' => 'orders.notify.event.updated', 'default_template' => 'orders.notify.tpl.updated'],
            $base + ['id' => 'orders.confirm', 'label' => 'orders.notify.event.confirm', 'default_template' => 'orders.notify.tpl.confirm'],
        ];
    }

    public static function emit(string $event, int $orderId, array $extra = []): void
    {
        if ($orderId < 1 || !self::active()) {
            return;
        }
        $order = OrdersRepository::find($orderId);
        if ($order === null && is_array($extra['order'] ?? null)) {
            $order = $extra['order'];
        }
        if ($order === null) {
            return;
        }
        $ctx = self::contextFromOrder($order);
        if (isset($extra['cancelled_by'])) {
            $ctx['cancelled_by'] = (string)$extra['cancelled_by'];
        }
        if (isset($extra['reason'])) {
            $ctx['reason'] = trim((string)$extra['reason']);
        }
        $clientId = (int)($order['id_user'] ?? 0);
        $operatorId = (int)($order['id_cosmetolog'] ?? 0);
        $payload = [
            'event' => $event,
            'entity' => 'order',
            'entity_id' => $orderId,
            'client_id' => $clientId,
            'anchor_ts' => (int)($order['dt_event'] ?? $order['DT_EVENT_NEW'] ?? 0),
            'ctx' => $ctx,
            'subjects' => [
                'client' => ['client_id' => $clientId],
                'operator' => ['user_id' => $operatorId],
            ],
            'closes_tasks' => !empty($extra['closes_tasks']),
            'dedupe_suffix' => (string)($extra['dedupe_suffix'] ?? ''),
        ];
        EventBus::emit('notify.event', $payload);
    }

    /**
     * @param array<string,mixed> $order
     * @return array<string,mixed>
     */
    public static function contextFromOrder(array $order): array
    {
        $orderId = (int)($order['id'] ?? 0);
        $dt = (int)($order['dt_event'] ?? $order['DT_EVENT_NEW'] ?? 0);
        $clientId = (int)($order['id_user'] ?? 0);
        $clientName = trim((string)($order['client_name'] ?? ''));
        if ($clientName === '' && $clientId > 0 && class_exists('ClientsRepository', false)) {
            $c = ClientsRepository::find($clientId);
            if ($c !== null) {
                $clientName = trim((string)($c['name'] ?? ''));
            }
        }
        $phone = preg_replace('/\D+/', '', (string)($order['phone'] ?? '')) ?? '';
        $staffId = (int)($order['id_cosmetolog'] ?? 0);
        $master = trim((string)($order['staff_name'] ?? ''));
        $operatorHidden = false;
        if ($staffId > 0 && class_exists('UsersRepository', false)) {
            $u = UsersRepository::find($staffId);
            if ($u !== null) {
                if ($master === '') {
                    $master = trim((string)($u['name'] ?? ''));
                }
                $operatorHidden = !empty($u['is_hidden']) || (string)($u['IS_HIDDEN'] ?? '') === '1';
            }
        }
        $categories = [];
        $types = [];
        $names = [];
        $lines = $order['cart_lines'] ?? null;
        if (!is_array($lines) || $lines === []) {
            $lines = OrdersRepository::decodeCart((string)($order['cart'] ?? ''));
        }
        foreach ((array)$lines as $line) {
            if (!is_array($line)) {
                continue;
            }
            $n = trim((string)($line['name'] ?? ''));
            $pid = (int)($line['id'] ?? 0);
            if ($pid > 0 && class_exists('ProductsRepository', false)) {
                $p = ProductsRepository::find($pid);
                if ($p !== null) {
                    if ($n === '') {
                        $n = trim((string)($p['name'] ?? ''));
                    }
                    $cat = (int)($p['category'] ?? $p['CATEGORY'] ?? 0);
                    if ($cat > 0) {
                        $categories[] = $cat;
                    }
                    $tid = (int)($p['type_id'] ?? $p['TYPE_ID'] ?? 0);
                    if ($tid > 0) {
                        $types[] = $tid;
                    }
                }
            }
            if ($n !== '') {
                $names[] = $n;
            }
        }
        $leadHours = 0.0;
        if ($dt > 0) {
            $leadHours = round(($dt - time()) / 3600, 2);
        }
        return [
            'order_id' => $orderId,
            'client_name' => $clientName !== '' ? $clientName : '—',
            'phone' => $phone !== '' ? $phone : '—',
            'service' => $names !== [] ? implode(', ', $names) : '—',
            'source' => (string)($order['source'] ?? ''),
            'when' => $dt > 0 ? date('d.m.Y H:i', $dt) : '—',
            'master' => $master !== '' ? $master : '—',
            'sum' => (float)($order['summ_real'] ?? $order['summ'] ?? 0),
            'product_category' => array_values(array_unique($categories)),
            'procedure_type' => array_values(array_unique($types)),
            'operator_id' => $staffId,
            'operator_hidden' => $operatorHidden ? '1' : '0',
            'lead_hours' => $leadHours,
            'dt_event' => $dt,
            'status' => (string)($order['status'] ?? ''),
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function scanWindow(int $from, int $to): array
    {
        if (!Database::tableExists('base_orders')) {
            return [];
        }
        $st = Database::pdo()->prepare(
            "SELECT * FROM base_orders
             WHERE STATUS = 'cashComplete'
               AND DT_EVENT_NEW >= ? AND DT_EVENT_NEW <= ?
             ORDER BY DT_EVENT_NEW ASC
             LIMIT 500"
        );
        $st->execute([$from, $to]);
        $out = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            if (!is_array($row)) {
                continue;
            }
            $norm = OrdersRepository::find((int)$row['ID']);
            if ($norm === null) {
                continue;
            }
            $ctx = self::contextFromOrder($norm);
            $out[] = [
                'event' => 'orders.confirm',
                'entity' => 'order',
                'entity_id' => (int)$norm['id'],
                'client_id' => (int)($norm['id_user'] ?? 0),
                'anchor_ts' => (int)($norm['dt_event'] ?? 0),
                'ctx' => $ctx,
                'subjects' => [
                    'client' => ['client_id' => (int)($norm['id_user'] ?? 0)],
                    'operator' => ['user_id' => (int)($norm['id_cosmetolog'] ?? 0)],
                ],
                'dedupe_suffix' => '',
            ];
        }
        return $out;
    }
}
