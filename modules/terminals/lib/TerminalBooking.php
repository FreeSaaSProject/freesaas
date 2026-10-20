<?php
declare(strict_types=1);

/**
 * Procedure-mode book/cancel for floor /t and legacy api.php.
 * Slots from booking; writes base_procedures; optional 1C sale/unsale.
 * Chill (rest) procedure after TIME_NET chain — legacy ID_GOOD chill product.
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
     *   sale_id?:string,
     *   document_id?:string,
     *   onec_bracelet?:string,
     *   price?:float,
     *   token?:string,
     *   order_id?:int,
     *   disable_1c?:bool,
     *   employee?:bool,
     *   staff_user_id?:int,
     *   notice?:string,
     *   lead_sec?:int,
     *   skip_chill?:bool,
     *   time_chill?:int
     * } $input
     * @return array{
     *   ok:bool,
     *   procedure_id?:int,
     *   chill_procedure_id?:int,
     *   sale_id?:string,
     *   payment_id?:int,
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
        // Procedures always need local client + bracelet (legacy api.php bookService gate).
        // Order-only booking without identity stays on /api_termy.php → BookingBook, not here.
        $clientId = max(0, (int)($input['client_id'] ?? 0));
        $bracelet = trim((string)($input['onec_bracelet'] ?? ''));
        if ($clientId < 1 || $bracelet === '') {
            return [
                'ok' => false,
                'error' => 'client_id and bracelet required for procedure',
                'stack' => $stack,
            ];
        }
        if ($adminId < 1) {
            $adminId = $operatorId;
        }

        $staffUserId = max(0, (int)($input['staff_user_id'] ?? 0));
        $isEmployee = !empty($input['employee']) || $staffUserId > 0;

        $leadSec = isset($input['lead_sec'])
            ? max(0, (int)$input['lead_sec'])
            : (class_exists(TerminalsConfig::class, false) ? TerminalsConfig::leadSec() : 180);

        // Employee floor: oper virtual sale idSale=999 — force stub / no live 1C.
        $disable1c = $isEmployee
            || !empty($input['disable_1c'])
            || TerminalsConfig::onecStubMode(false);
        // Live 1C: full slot/shift gate. Stub/force-majeure: still refuse past starts, skip shift matrix.
        if (!$disable1c && class_exists(BookingValidate::class, false)) {
            try {
                $slotOpts = class_exists(TerminalsConfig::class, false)
                    ? TerminalsConfig::bookingSlotOpts()
                    : [];
                if (isset($input['interval_sec'])) {
                    $slotOpts['interval_sec'] = class_exists(BookingSlots::class, false)
                        ? BookingSlots::normalizeIntervalSec((int)$input['interval_sec'], BookingSlots::INTERVAL_SEC)
                        : max(300, (int)$input['interval_sec']);
                }
                BookingValidate::assertSlot(
                    [$productId],
                    $operatorId,
                    $dtStart,
                    null,
                    false,
                    $leadSec,
                    null,
                    $slotOpts
                );
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
        $timeSec = max(0, (int)($product['time_sec'] ?? 0));
        $timeNet = max(0, (int)($product['time_net'] ?? 0));
        if ($timeSec < 1) {
            $timeSec = 3600;
        }
        if ($timeNet < 1) {
            $timeNet = $timeSec;
        }
        if ($timeNet > $timeSec) {
            $timeNet = $timeSec;
        }
        $dtEnd = $dtStart + $timeNet;
        $listPrice = isset($input['price_list']) ? (float)$input['price_list'] : 0.0;
        if ($listPrice <= 0) {
            $listPrice = (float)($product['price'] ?? 0);
            if ($listPrice <= 0) {
                $listPrice = (float)($product['price3'] ?? 0);
            }
        }
        $price = isset($input['price']) ? (float)$input['price'] : $listPrice;
        if ($price <= 0) {
            $price = $listPrice;
        }
        $typeId = (int)($product['type'] ?? $product['type_id'] ?? 0);
        $onecProduct = trim((string)($product['onec_id'] ?? ''));
        if ($onecProduct === '') {
            $onecProduct = TerminalsConfig::string('1c_uid', '');
        }

        $onecClient = trim((string)($input['onec_client_id'] ?? ''));
        // Pre-paid sale (legacy api.php: document_id = idSale after api1c.php/payment).
        $preSaleId = trim((string)($input['sale_id'] ?? $input['document_id'] ?? ''));
        $saleId = '';
        $saleStub = false;
        if ($isEmployee) {
            // Oper: virtual idSale=999 for staff-as-client (excluded from dayColumnStats revenue).
            $saleId = '999';
            $saleStub = true;
            $stack[] = [
                'key' => 'sale',
                'ok' => true,
                'stub' => true,
                'employee' => true,
                'sale_id' => $saleId,
                'price' => $price,
                'detail' => 'employee virtual sale',
            ];
        } elseif ($preSaleId !== '') {
            $saleId = $preSaleId;
            $stack[] = [
                'key' => 'sale',
                'ok' => true,
                'skipped' => true,
                'preexisting' => true,
                'sale_id' => $saleId,
                'price' => $price,
                'detail' => 'sale_id provided',
            ];
        } elseif ($onecClient !== '' && $onecProduct !== '' && $price > 0) {
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
                'id_user' => $clientId,
                'id_terminal' => max(0, $terminalId),
                'type' => $typeId,
                'summ' => $price,
                'summ_default' => $listPrice > 0 ? $listPrice : $price,
                'notice' => $notice,
                'enabled' => 1,
                'token' => $token,
                'id_event_parent' => 0,
                'onec_id' => $saleId,
                'onec_bracelet' => $bracelet,
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

        $paymentId = null;
        if ($isEmployee && $staffUserId > 0 && class_exists(EmployeeProcedureSettle::class, false)) {
            try {
                $paymentId = EmployeeProcedureSettle::applyOnBook($procId, $staffUserId, $price, $dtStart);
                $stack[] = [
                    'key' => 'employee_settle',
                    'ok' => true,
                    'payment_id' => $paymentId,
                    'skipped' => $paymentId === null,
                ];
            } catch (Throwable $e) {
                $stack[] = [
                    'key' => 'employee_settle',
                    'ok' => false,
                    'detail' => $e->getMessage(),
                ];
            }
        }

        $chillId = 0;
        $skipChill = !empty($input['skip_chill']);
        $timeChill = isset($input['time_chill']) ? max(0, (int)$input['time_chill']) : -1;
        if (!$skipChill) {
            if ($timeChill < 0) {
                $loaded = class_exists(BookingAvailability::class, false)
                    ? BookingAvailability::loadProducts([$productId])
                    : [[
                        'id' => $productId,
                        'time_sec' => $timeSec,
                        'time_net' => $timeNet,
                        'type_id' => $typeId,
                        'name' => $productName,
                    ]];
                if (class_exists(BookingSlots::class, false) && $loaded !== []) {
                    $probe = BookingSlots::check($loaded, $operatorId, [], $dtStart, $dtStart + 86400);
                    $timeChill = (int)$probe['timeChill'];
                } elseif (class_exists(BookingSlots::class, false)) {
                    $capped = BookingSlots::durationWithChill($timeSec, $timeNet);
                    $timeChill = $capped['time_chill'];
                } else {
                    $timeChill = max(0, $timeSec - $timeNet);
                }
            }
            if ($timeChill > 0) {
                $chillId = self::insertChill([
                    'dt_start' => $dtEnd,
                    'time_chill' => $timeChill,
                    'operator_id' => $operatorId,
                    'admin_id' => $adminId,
                    'terminal_id' => $terminalId,
                    'client_id' => $clientId,
                    'onec_bracelet' => $bracelet,
                    'token' => md5($token . '#chill'),
                    'parent_procedure_id' => $procId,
                ]);
                if ($chillId > 0) {
                    $stack[] = [
                        'key' => 'chill',
                        'ok' => true,
                        'procedure_id' => $chillId,
                        'dt_end' => $dtEnd + $timeChill,
                        'minutes' => round($timeChill / 60, 1),
                    ];
                } else {
                    $stack[] = [
                        'key' => 'chill',
                        'ok' => false,
                        'skipped' => $chillId === 0 && TerminalsConfig::chillProductId() < 1,
                        'detail' => $chillId < 0 ? 'chill insert failed' : 'chill disabled',
                    ];
                }
            }
        }

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
        if ($paymentId !== null && $paymentId > 0) {
            $out['payment_id'] = $paymentId;
        }
        if ($chillId > 0) {
            $out['chill_procedure_id'] = $chillId;
        }
        if (!$adminOk) {
            $out['error'] = 'procedure not visible in admin';
        }
        return $out;
    }

    /**
     * Cancel (disable) a procedure and optionally unsale in 1C.
     * Also disables linked chill when main procedure ends at chill DTSTART.
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

        $chillCancelled = self::cancelLinkedChill($row, $notice);
        if ($chillCancelled > 0) {
            $stack[] = [
                'key' => 'chill',
                'ok' => true,
                'procedure_id' => $chillCancelled,
                'cancel' => true,
            ];
        }

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

    /**
     * @param array{
     *   dt_start:int,
     *   time_chill:int,
     *   operator_id:int,
     *   admin_id:int,
     *   terminal_id:int,
     *   client_id:int,
     *   onec_bracelet:string,
     *   token:string,
     *   parent_procedure_id:int
     * } $in
     * @return int chill procedure id, 0 if disabled/skipped, -1 on failure
     */
    private static function insertChill(array $in): int
    {
        $chillGood = TerminalsConfig::chillProductId();
        if ($chillGood < 1 || $in['time_chill'] < 1) {
            return 0;
        }
        $chillProduct = class_exists(ProductsRepository::class, false)
            ? ProductsRepository::find($chillGood)
            : null;
        $typeId = $chillProduct
            ? (int)($chillProduct['type'] ?? $chillProduct['type_id'] ?? 0)
            : 0;
        $dtStart = (int)$in['dt_start'];
        $dtEnd = $dtStart + (int)$in['time_chill'];
        try {
            $id = ProceduresRepository::create([
                'dtstart' => $dtStart,
                'dtend' => $dtEnd,
                'dtend_plan' => $dtEnd,
                'id_operator' => (int)$in['operator_id'],
                'id_admin' => (int)$in['admin_id'],
                'id_good' => $chillGood,
                'id_table' => 1,
                'id_user' => max(0, (int)$in['client_id']),
                'id_terminal' => max(0, (int)$in['terminal_id']),
                'type' => $typeId,
                'summ' => 0,
                'summ_default' => 0,
                'notice' => 'chill#' . (int)$in['parent_procedure_id'],
                'enabled' => 1,
                'token' => substr((string)$in['token'], 0, 32),
                'id_event_parent' => 0,
                'onec_id' => '',
                'onec_bracelet' => (string)$in['onec_bracelet'],
                'restored' => 0,
            ]);
            return $id > 0 ? $id : -1;
        } catch (Throwable $e) {
            return -1;
        }
    }

    /**
     * @param array<string,mixed> $main
     */
    private static function cancelLinkedChill(array $main, string $notice): int
    {
        $chillGood = TerminalsConfig::chillProductId();
        if ($chillGood < 1) {
            return 0;
        }
        $dtEnd = (int)($main['dtend'] ?? 0);
        $op = (int)($main['id_operator'] ?? 0);
        if ($dtEnd < 1 || $op < 1 || !Database::tableExists('base_procedures')) {
            return 0;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID FROM base_procedures
             WHERE ENABLED = 1 AND ID_GOOD = ? AND ID_OPERATOR = ? AND DTSTART = ?
             ORDER BY ID ASC LIMIT 1'
        );
        $st->execute([$chillGood, $op, $dtEnd]);
        $chillId = (int)$st->fetchColumn();
        if ($chillId < 1) {
            return 0;
        }
        Database::pdo()->prepare(
            'UPDATE base_procedures SET ENABLED = 0, SUMM = 0, NOTICE = ? WHERE ID = ?'
        )->execute([mb_substr('chill-cancel ' . $notice, 0, 255), $chillId]);
        return $chillId;
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
