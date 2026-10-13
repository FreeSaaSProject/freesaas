<?php
declare(strict_types=1);

/**
 * Enqueue Max-group notify for order create/cancel via bots bus (base_telegram_sends).
 */
final class OrdersMaxNotify
{
    public static function onCreated(int $orderId): void
    {
        if ($orderId < 1 || !self::ready()) {
            return;
        }
        $order = OrdersRepository::find($orderId);
        if ($order === null) {
            return;
        }
        self::enqueue($order, false, '');
    }

    /**
     * @param array<string,mixed> $order normalized order row (pre-cancel snapshot ok)
     */
    public static function onCancelled(array $order, string $reason = ''): void
    {
        if (!self::ready()) {
            return;
        }
        $id = (int)($order['id'] ?? 0);
        if ($id < 1) {
            return;
        }
        self::enqueue($order, true, $reason);
    }

    private static function ready(): bool
    {
        if (!OrdersConfig::maxNotifyEnabled()) {
            return false;
        }
        if (OrdersConfig::maxNotifyChatId() === '') {
            return false;
        }
        if (!ModuleManager::isEnabled('bots') || !class_exists('BotSend', false)) {
            return false;
        }
        return true;
    }

    /**
     * @param array<string,mixed> $order
     */
    private static function enqueue(array $order, bool $cancel, string $reason): void
    {
        $orderId = (int)($order['id'] ?? 0);
        $chatId = OrdersConfig::maxNotifyChatId();
        $titleOverride = $cancel ? OrdersConfig::maxNotifyTitleCancel() : OrdersConfig::maxNotifyTitle();
        $title = $titleOverride !== ''
            ? $titleOverride
            : t($cancel ? 'orders.max_notify.title_cancel' : 'orders.max_notify.title_new');

        $text = self::formatMessage($title, $order, $reason);
        $kind = $cancel ? 'cancel' : 'created';
        try {
            BotSend::enqueue([
                'audience' => 'staff',
                'chat_id' => $chatId,
                'platform' => 'max',
                'text' => $text,
                'name' => 'order_' . $kind . '_' . $orderId,
                'dedupe_key' => 'order_' . $kind . '_' . $orderId,
                'type' => 13,
                'id_event' => $orderId,
                'id_operator' => 0,
            ]);
            if (class_exists('BotDrain', false)) {
                BotDrain::run(5);
            }
        } catch (Throwable $e) {
            error_log('[orders.max_notify] ' . $e->getMessage());
        }
    }

    /**
     * @param array<string,mixed> $order
     */
    private static function formatMessage(string $title, array $order, string $reason): string
    {
        $clientName = trim((string)($order['client_name'] ?? ''));
        $clientId = (int)($order['id_user'] ?? 0);
        if ($clientName === '' && $clientId > 0 && class_exists(ClientsRepository::class, false)) {
            $c = ClientsRepository::find($clientId);
            if ($c !== null) {
                $clientName = trim((string)($c['name'] ?? ''));
            }
        }
        $phone = preg_replace('/\D+/', '', (string)($order['phone'] ?? '')) ?? '';
        if ($phone === '' && !empty($order['client_tel'])) {
            $phone = preg_replace('/\D+/', '', (string)$order['client_tel']) ?? '';
        }

        $dt = (int)($order['dt_event'] ?? 0);
        $dateLabel = $dt > 0 ? date('d.m.Y H:i', $dt) : '';

        $serviceName = self::serviceLabel($order);
        $masterName = trim((string)($order['staff_name'] ?? ''));
        if ($masterName === '') {
            $staffId = (int)($order['id_cosmetolog'] ?? 0);
            if ($staffId > 0 && class_exists(UsersRepository::class, false)) {
                $u = UsersRepository::find($staffId);
                if ($u !== null) {
                    $masterName = trim((string)($u['name'] ?? ''));
                }
            }
            if ($masterName === '' && $staffId > 0) {
                $masterName = 'ID ' . $staffId;
            }
        }

        $source = trim((string)($order['source'] ?? ''));
        if ($source === '') {
            $source = '-';
        }

        $orderId = (int)($order['id'] ?? 0);
        $esc = static function (string $v): string {
            return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        };

        $lines = [
            '<b>' . $esc($title) . '</b>',
            t('orders.max_notify.line_source', ['source' => $esc($source)]),
            t('orders.max_notify.line_client', [
                'name' => $esc($clientName !== '' ? $clientName : '—'),
                'id' => $clientId,
            ]),
            t('orders.max_notify.line_tel', ['tel' => $esc($phone !== '' ? $phone : '—')]),
            t('orders.max_notify.line_date', ['date' => $esc($dateLabel !== '' ? $dateLabel : '—')]),
            t('orders.max_notify.line_service', ['service' => $esc($serviceName)]),
            t('orders.max_notify.line_master', ['master' => $esc($masterName !== '' ? $masterName : '—')]),
        ];
        if ($orderId > 0) {
            $lines[] = t('orders.max_notify.line_order', ['id' => $orderId]);
        }
        $reason = trim($reason);
        if ($reason !== '') {
            $lines[] = t('orders.max_notify.line_reason', ['reason' => $esc($reason)]);
        }
        return implode("\n", $lines);
    }

    /**
     * @param array<string,mixed> $order
     */
    private static function serviceLabel(array $order): string
    {
        $lines = $order['cart_lines'] ?? null;
        if (!is_array($lines) || $lines === []) {
            $cart = (string)($order['cart'] ?? '');
            if ($cart !== '' && class_exists(OrdersRepository::class, false)) {
                $lines = OrdersRepository::decodeCart($cart);
            }
        }
        if (!is_array($lines) || $lines === []) {
            return '—';
        }
        $names = [];
        foreach ($lines as $line) {
            if (!is_array($line)) {
                continue;
            }
            $n = trim((string)($line['name'] ?? ''));
            if ($n === '') {
                $pid = (int)($line['id'] ?? 0);
                if ($pid > 0 && class_exists(ProductsRepository::class, false)) {
                    $p = ProductsRepository::find($pid);
                    $n = $p ? trim((string)($p['name'] ?? '')) : '';
                }
                if ($n === '' && $pid > 0) {
                    $n = 'ID ' . $pid;
                }
            }
            if ($n !== '') {
                $names[] = $n;
            }
        }
        return $names !== [] ? implode(', ', $names) : '—';
    }
}
