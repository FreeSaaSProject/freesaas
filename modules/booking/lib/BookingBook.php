<?php
declare(strict_types=1);

/**
 * Order-mode book: advance row in base_orders (pay later on floor).
 */
final class BookingBook
{
    /**
     * @param array{
     *   phone:string,
     *   client_id?:int,
     *   client_name?:string,
     *   product_ids:list<int>,
     *   cart_lines?:list<array{id:int,name?:string,price?:float,price_original?:float}>,
     *   operator_id:int,
     *   dt_start:int,
     *   comment?:string,
     *   mail?:string,
     *   free_ticket?:int
     * } $input
     * @return array{order_id:int,uid:string,dt_start:int,operator_id:int,summ:float,client_id:int,client_created:bool}
     */
    public static function createOrder(array $input): array
    {
        if (!class_exists(OrdersRepository::class, false) || !Database::tableExists('base_orders')) {
            throw new RuntimeException(t('booking.err.orders'));
        }

        $phone = preg_replace('/\D+/', '', (string)($input['phone'] ?? '')) ?? '';
        if ($phone === '') {
            throw new InvalidArgumentException(t('booking.err.phone'));
        }
        $clientName = trim((string)($input['client_name'] ?? ''));
        $operatorId = (int)($input['operator_id'] ?? 0);
        if ($operatorId < 1) {
            throw new InvalidArgumentException(t('booking.err.operator'));
        }
        $dtStart = (int)($input['dt_start'] ?? 0);
        if ($dtStart < 1) {
            throw new InvalidArgumentException(t('booking.err.dt_start'));
        }
        // Keep duplicates (qty) for duration; unique only for empty check.
        $productIds = array_values(array_filter(
            array_map('intval', $input['product_ids'] ?? []),
            static fn (int $id): bool => $id > 0
        ));
        if ($productIds === []) {
            throw new InvalidArgumentException(t('booking.err.products'));
        }

        BookingValidate::assertSlot($productIds, $operatorId, $dtStart, null, false);

        $lines = [];
        $summ = 0.0;
        if (!empty($input['cart_lines']) && is_array($input['cart_lines'])) {
            foreach ($input['cart_lines'] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $id = (int)($row['id'] ?? 0);
                if ($id < 1) {
                    continue;
                }
                $price = (float)($row['price'] ?? 0);
                $priceOrig = (float)($row['price_original'] ?? $price);
                $lines[] = [
                    'id' => $id,
                    'name' => (string)($row['name'] ?? ''),
                    'price' => $price,
                    'price_original' => $priceOrig > 0 ? $priceOrig : $price,
                ];
                $summ += $price;
            }
        }
        if ($lines === []) {
            $products = BookingAvailability::loadProducts($productIds);
            foreach ($products as $p) {
                $full = ProductsRepository::find((int)$p['id']);
                $price = $full ? (float)($full['price3'] ?? 0) : 0.0;
                if ($price <= 0 && $full) {
                    $price = (float)($full['price'] ?? 0);
                }
                $lines[] = [
                    'id' => (int)$p['id'],
                    'name' => (string)$p['name'],
                    'price' => $price,
                    'price_original' => $price,
                ];
                $summ += $price;
            }
        }

        $clientCreated = false;
        $clientId = max(0, (int)($input['client_id'] ?? 0));
        if (class_exists(ClientsRepository::class, false) && Database::tableExists('base_clients')) {
            $client = null;
            if ($clientId > 0) {
                $client = ClientsRepository::find($clientId);
                if ($client !== null) {
                    $telDigits = preg_replace('/\D+/', '', (string)($client['tel'] ?? '')) ?? '';
                    if ($telDigits !== '' && $telDigits !== $phone) {
                        // Stale pick — fall back to phone match / create
                        $client = null;
                        $clientId = 0;
                    }
                } else {
                    $clientId = 0;
                }
            }
            if ($client === null) {
                $client = ClientsRepository::findByTel($phone);
            }
            if ($client === null && strlen($phone) >= 10) {
                // try last-10 match via list
                $tail = substr($phone, -10);
                $found = ClientsRepository::list([
                    'q' => $tail,
                    'enabled' => null,
                    'limit' => 5,
                    'offset' => 0,
                ]);
                foreach ($found['items'] as $row) {
                    $digits = preg_replace('/\D+/', '', (string)($row['tel'] ?? '')) ?? '';
                    if ($digits === $phone || substr($digits, -10) === $tail) {
                        $client = $row;
                        break;
                    }
                }
            }
            if (is_array($client)) {
                $clientId = (int)($client['id'] ?? 0);
                if ($clientName === '') {
                    $clientName = trim((string)($client['name'] ?? ''));
                }
            } else {
                $createName = $clientName !== '' ? $clientName : $phone;
                $clientId = ClientsRepository::create(ClientsRepository::dataFromPost([
                    'tel' => $phone,
                    'name' => $createName,
                    'mail' => (string)($input['mail'] ?? ''),
                    'enabled' => '1',
                ]));
                $clientCreated = true;
            }
        }

        $payload = [
            'phone' => $phone,
            'mail' => (string)($input['mail'] ?? ''),
            'comment' => (string)($input['comment'] ?? ''),
            'cart_lines' => $lines,
            'summ' => round($summ, 2),
            'summ_real' => round($summ, 2),
            'status' => 'cashComplete',
            'dt_event' => $dtStart,
            'id_user' => $clientId,
            'id_cosmetolog' => $operatorId,
            'free_ticket' => !empty($input['free_ticket']) ? 1 : 0,
        ];

        $before = EventBus::emit('booking.book.before', [
            'input' => $input,
            'payload' => $payload,
            'error' => null,
        ]);
        if (!empty($before['error'])) {
            throw new RuntimeException((string)$before['error']);
        }
        if (isset($before['payload']) && is_array($before['payload'])) {
            $payload = $before['payload'];
        }

        $orderId = OrdersRepository::create($payload);
        $row = OrdersRepository::find($orderId);

        EventBus::emit('booking.book.after', [
            'order_id' => $orderId,
            'payload' => $payload,
            'row' => $row,
        ]);

        return [
            'order_id' => $orderId,
            'uid' => (string)($row['uid'] ?? ''),
            'dt_start' => $dtStart,
            'operator_id' => $operatorId,
            'summ' => round($summ, 2),
            'client_id' => $clientId,
            'client_created' => $clientCreated,
        ];
    }
}
