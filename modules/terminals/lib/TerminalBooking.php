<?php
declare(strict_types=1);

/**
 * Procedure-mode book/cancel for floor /t and legacy api.php.
 * Slots from booking; writes base_procedures; optional 1C sale/unsale.
 */
final class TerminalBooking
{
    /**
     * @param array{
     *   product_id:int,
     *   operator_id:int,
     *   admin_id:int,
     *   terminal_id:int,
     *   dt_start:int,
     *   client_id?:int,
     *   onec_client_id?:string,
     *   onec_bracelet?:string,
     *   price?:float,
     *   token?:string,
     *   order_id?:int,
     *   disable_1c?:bool,
     *   notice?:string
     * } $input
     * @return array{
     *   ok:bool,
     *   procedure_id?:int,
     *   sale_id?:string,
     *   product_name?:string,
     *   price?:float,
     *   dt_end?:int,
     *   error?:string,
     *   stack?:list<array<string,mixed>>
     * }
     */
    public static function book(array $input): array
    {
        $stack = [];
        if (!class_exists(ProceduresRepository::class, false) || !Database::tableExists('base_procedures')) {
            return ['ok' => false, 'error' => 'procedures unavailable', 'stack' => $stack];
        }
        $productId = (int)($input['product_id'] ?? 0);
        $operatorId = (int)($input['operator_id'] ?? 0);
        $adminId = (int)($input['admin_id'] ?? 0);
        $terminalId = (int)($input['terminal_id'] ?? 0);
        $dtStart = (int)($input['dt_start'] ?? 0);
        if ($productId < 1 || $operatorId < 1 || $dtStart < 1) {
            return ['ok' => false, 'error' => 'product_id, operator_id, dt_start required', 'stack' => $stack];
        }
        if ($adminId < 1) {
            $adminId = $operatorId;
        }

        $disable1c = !empty($input['disable_1c']) || TerminalsConfig::onecStubMode(false);
        // Live 1C: full slot/shift gate. Stub/force-majeure: still refuse past starts, skip shift matrix.
        if (!$disable1c && class_exists(BookingValidate::class, false)) {
            try {
                BookingValidate::assertSlot([$productId], $operatorId, $dtStart, null, false);
            } catch (Throwable $e) {
                return ['ok' => false, 'error' => $e->getMessage(), 'stack' => $stack];
            }
        } elseif ($dtStart < time() - 120) {
            return ['ok' => false, 'error' => 'dt_start in the past', 'stack' => $stack];
        }

        $product = class_exists(ProductsRepository::class, false)
            ? ProductsRepository::find($productId)
            : null;
        if ($product === null) {
            return ['ok' => false, 'error' => 'product not found', 'stack' => $stack];
        }
        $productName = trim((string)($product['name'] ?? ('#' . $productId)));
        $duration = (int)($product['time_sec'] ?? 0);
        if ($duration < 1) {
            $duration = 3600;
        }
        $dtEnd = $dtStart + $duration;
        $price = isset($input['price']) ? (float)$input['price'] : (float)($product['price'] ?? 0);
        if ($price <= 0) {
            $price = (float)($product['price3'] ?? 0);
        }
        $typeId = (int)($product['type'] ?? $product['type_id'] ?? 0);
        $onecProduct = trim((string)($product['onec_id'] ?? ''));
        if ($onecProduct === '') {
            $onecProduct = TerminalsConfig::string('1c_uid', '');
        }

        $onecClient = trim((string)($input['onec_client_id'] ?? ''));
        $saleId = '';
        $saleStub = false;
        if ($onecClient !== '' && $onecProduct !== '' && $price > 0) {
            $sale = OnecClient::sale($onecClient, $onecProduct, $price, $disable1c);
            if (empty($sale['ok'])) {
                $stack[] = [
                    'key' => 'sale',
                    'ok' => false,
                    'stub' => !empty($sale['stub']),
                    'price' => $price,
                    'detail' => (string)($sale['error'] ?? '1C sale failed'),
                ];
                return [
                    'ok' => false,
                    'error' => (string)($sale['error'] ?? '1C sale failed'),
                    'product_name' => $productName,
                    'price' => $price,
                    'stack' => $stack,
                ];
            }
            $saleId = (string)($sale['sale_id'] ?? '');
            $saleStub = !empty($sale['stub']) || $disable1c;
            $stack[] = [
                'key' => 'sale',
                'ok' => true,
                'stub' => $saleStub,
                'sale_id' => $saleId,
                'price' => $price,
            ];
        } else {
            $stack[] = [
                'key' => 'sale',
                'ok' => false,
                'skipped' => true,
                'price' => $price,
                'detail' => 'sale skipped',
            ];
        }

        $token = substr(trim((string)($input['token'] ?? '')), 0, 32);
        if ($token === '') {
            $token = bin2hex(random_bytes(16));
        } elseif (self::tokenUsed($token)) {
            return [
                'ok' => false,
                'error' => 'token already used',
                'product_name' => $productName,
                'price' => $price,
                'stack' => $stack,
            ];
        }

        $notice = (string)($input['notice'] ?? '');
        if ($disable1c && $notice === '') {
            $notice = 'Отключено от 1С администратором!';
        }

        $procId = 0;
        try {
            $procId = ProceduresRepository::create([
                'dtstart' => $dtStart,
                'dtend' => $dtEnd,
                'dtend_plan' => $dtEnd,
                'id_operator' => $operatorId,
                'id_admin' => $adminId,
                'id_good' => $productId,
                'id_table' => 1,
                'id_user' => max(0, (int)($input['client_id'] ?? 0)),
                'id_terminal' => max(0, $terminalId),
                'type' => $typeId,
                'summ' => $price,
                'summ_default' => $price,
                'notice' => $notice,
                'enabled' => 1,
                'token' => $token,
                'id_event_parent' => 0,
                'onec_id' => $saleId,
                'onec_bracelet' => (string)($input['onec_bracelet'] ?? ''),
                'restored' => 0,
            ]);
        } catch (Throwable $e) {
            $stack[] = [
                'key' => 'procedure',
                'ok' => false,
                'detail' => $e->getMessage(),
            ];
            $stack[] = ['key' => 'admin', 'ok' => false];
            return [
                'ok' => false,
                'error' => $e->getMessage(),
                'product_name' => $productName,
                'price' => $price,
                'stack' => $stack,
            ];
        }

        if ($procId < 1) {
            $stack[] = ['key' => 'procedure', 'ok' => false, 'detail' => 'insert returned 0'];
            $stack[] = ['key' => 'admin', 'ok' => false];
            return [
                'ok' => false,
                'error' => 'procedure insert failed',
                'product_name' => $productName,
                'price' => $price,
                'stack' => $stack,
            ];
        }

        $stack[] = [
            'key' => 'procedure',
            'ok' => true,
            'procedure_id' => $procId,
            'dt_end' => $dtEnd,
            'product_name' => $productName,
        ];

        $orderId = (int)($input['order_id'] ?? 0);
        if ($orderId > 0 && class_exists(OrdersRepository::class, false)) {
            self::consumeOrder($orderId, $procId, $adminId);
        }

        if ($terminalId > 0) {
            TerminalsRepository::clearExecutorLock($terminalId);
        }

        // Re-read from DB — green "in admin" marker for floor staff.
        $stored = ProceduresRepository::find($procId);
        $adminOk = is_array($stored)
            && (int)($stored['enabled'] ?? $stored['ENABLED'] ?? 0) === 1
            && (int)($stored['id_good'] ?? $stored['ID_GOOD'] ?? 0) === $productId;
        $stack[] = [
            'key' => 'admin',
            'ok' => $adminOk,
            'procedure_id' => $procId,
            'detail' => $adminOk ? '' : 'not found after insert',
        ];

        $out = [
            'ok' => $adminOk,
            'procedure_id' => $procId,
            'sale_id' => $saleId,
            'product_name' => $productName,
            'price' => $price,
            'dt_end' => $dtEnd,
            'stack' => $stack,
        ];
        if (!$adminOk) {
            $out['error'] = 'procedure not visible in admin';
        }
        return $out;
    }

    /**
     * Cancel (disable) a procedure and optionally unsale in 1C.
     *
     * @return array{ok:bool,error?:string,stack?:list<array<string,mixed>>}
     */
    public static function cancel(
        int $procedureId,
        bool $unsale = true,
        bool $disable1c = false,
        string $notice = '',
        string $token = ''
    ): array {
        $stack = [];
        if ($procedureId < 1 || !class_exists(ProceduresRepository::class, false)) {
            return ['ok' => false, 'error' => 'invalid procedure', 'stack' => $stack];
        }
        $row = ProceduresRepository::find($procedureId);
        if ($row === null) {
            return ['ok' => false, 'error' => 'not found', 'stack' => $stack];
        }
        $productName = (string)($row['product_name'] ?? ('#' . (int)($row['id_good'] ?? 0)));
        $price = (float)($row['summ'] ?? $row['summ_default'] ?? 0);
        $meta = [
            'procedure_id' => $procedureId,
            'product_name' => $productName,
            'price' => $price,
        ];
        if ((int)($row['enabled'] ?? 0) < 1) {
            return array_merge($meta, ['ok' => true, 'stack' => $stack]);
        }
        $onecId = trim((string)($row['onec_id'] ?? $row['1c_id'] ?? ''));
        if ($unsale && $onecId !== '') {
            $res = OnecClient::unsale($onecId, $disable1c || strpos($onecId, 'stub-') === 0);
            if (empty($res['ok'])) {
                $stack[] = [
                    'key' => 'unsale',
                    'ok' => false,
                    'detail' => (string)($res['error'] ?? 'unsale failed'),
                ];
                return array_merge($meta, [
                    'ok' => false,
                    'error' => (string)($res['error'] ?? 'unsale failed'),
                    'stack' => $stack,
                ]);
            }
            $stack[] = [
                'key' => 'unsale',
                'ok' => true,
                'stub' => !empty($res['stub']) || strpos($onecId, 'stub-') === 0,
                'sale_id' => $onecId,
            ];
        } else {
            $stack[] = ['key' => 'unsale', 'ok' => true, 'skipped' => true];
        }

        $notice = mb_substr(trim($notice), 0, 255);
        $token = substr(trim($token), 0, 32);
        if ($token === '') {
            $token = substr((string)($row['token'] ?? ''), 0, 32);
        }
        Database::pdo()->prepare(
            'UPDATE base_procedures SET ENABLED = 0, SUMM = 0, NOTICE = ?, TOKEN = ? WHERE ID = ?'
        )->execute([$notice, $token, $procedureId]);
        $stack[] = [
            'key' => 'procedure',
            'ok' => true,
            'procedure_id' => $procedureId,
            'cancel' => true,
        ];

        $stored = ProceduresRepository::find($procedureId);
        $adminOk = is_array($stored) && (int)($stored['enabled'] ?? 1) === 0;
        $stack[] = [
            'key' => 'admin',
            'ok' => $adminOk,
            'procedure_id' => $procedureId,
            'cancel' => true,
            'detail' => $adminOk ? '' : 'still enabled after cancel',
        ];
        $out = array_merge($meta, [
            'ok' => $adminOk,
            'stack' => $stack,
        ]);
        if (!$adminOk) {
            $out['error'] = 'procedure still enabled after cancel';
        }
        return $out;
    }

    private static function tokenUsed(string $token): bool
    {
        if (!Database::tableExists('base_procedures')) {
            return false;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID FROM base_procedures WHERE TOKEN = ? AND ENABLED = 1 LIMIT 1'
        );
        $st->execute([$token]);
        return (bool)$st->fetchColumn();
    }

    private static function consumeOrder(int $orderId, int $procedureId, int $adminId): void
    {
        if (!Database::tableExists('base_orders')) {
            return;
        }
        $order = OrdersRepository::find($orderId);
        if ($order === null) {
            return;
        }
        $status = (string)($order['status'] ?? '');
        if (!in_array($status, OrdersRepository::NEW_STATUSES, true)) {
            return;
        }
        Database::pdo()->prepare(
            "UPDATE base_orders SET STATUS = 'complete', ID_EVENT_USE = ?, DT_SAVE = ? WHERE ID = ?"
        )->execute([$procedureId, time(), $orderId]);
    }
}
