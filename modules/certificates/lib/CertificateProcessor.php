<?php
declare(strict_types=1);

/**
 * Validate / quote / apply / undo / expire certificates.
 * Ledger is the money source of truth; USED_AMOUNT is a denormalized cache.
 */
final class CertificateProcessor
{
    /**
     * @param array{
     *   product_ids?:list<int>,
     *   type_ids?:list<int>,
     *   amount?:float,
     *   check_sum?:float,
     *   bonus_discount?:float,
     *   allow_unbound_holder?:bool
     * } $context
     * @return array{ok:bool,error?:string,cert?:array<string,mixed>,balance?:float}
     */
    public static function validate($codeOrId, int $clientId, array $context = []): array
    {
        $cert = self::resolveCert($codeOrId);
        if ($cert === null) {
            return ['ok' => false, 'error' => t('certificates.err.not_found_code')];
        }
        if (!empty($cert['expired'])) {
            return ['ok' => false, 'error' => t('certificates.err.expired'), 'cert' => $cert];
        }
        $now = time();
        if ((int)$cert['dt_use_expire'] > 0 && (int)$cert['dt_use_expire'] < $now) {
            return ['ok' => false, 'error' => t('certificates.err.use_expired'), 'cert' => $cert];
        }
        if ((int)$cert['dt_activation_expire'] > 0
            && (int)$cert['id_holder'] < 1
            && (int)$cert['dt_activation_expire'] < $now
        ) {
            return ['ok' => false, 'error' => t('certificates.err.activation_expired'), 'cert' => $cert];
        }
        $holder = (int)$cert['id_holder'];
        if ($holder > 0 && $clientId > 0 && $holder !== $clientId) {
            return ['ok' => false, 'error' => t('certificates.err.wrong_holder'), 'cert' => $cert];
        }
        if ($holder < 1 && empty($context['allow_unbound_holder'])) {
            return ['ok' => false, 'error' => t('certificates.err.need_holder'), 'cert' => $cert];
        }
        if ($clientId < 1) {
            return ['ok' => false, 'error' => t('certificates.err.need_client'), 'cert' => $cert];
        }

        $balance = CertificateLedgerRepository::tableReady()
            ? CertificateLedgerRepository::balance((int)$cert['id'])
            : (float)$cert['balance'];
        if ($balance <= 0) {
            return ['ok' => false, 'error' => t('certificates.err.empty'), 'cert' => $cert, 'balance' => $balance];
        }

        if (!empty($cert['bonus_forbidden']) && (float)($context['bonus_discount'] ?? 0) > 0) {
            return ['ok' => false, 'error' => t('certificates.err.bonus_forbidden'), 'cert' => $cert, 'balance' => $balance];
        }

        $productIds = array_map('intval', (array)($context['product_ids'] ?? []));
        $typeIds = array_map('intval', (array)($context['type_ids'] ?? []));
        if ((int)$cert['kind'] === CertificatesRepository::KIND_MONEY) {
            $allowedProducts = (array)($cert['product_ids'] ?? []);
            $allowedTypes = (array)($cert['type_ids'] ?? []);
            if ($allowedProducts !== [] || $allowedTypes !== []) {
                if (!self::goodsMatchRestrictions($productIds, $typeIds, $allowedProducts, $allowedTypes)) {
                    return ['ok' => false, 'error' => t('certificates.err.goods_restricted'), 'cert' => $cert, 'balance' => $balance];
                }
            }
            $limitPct = (int)($cert['use_limit_pct'] ?? 0);
            $checkSum = (float)($context['check_sum'] ?? 0);
            $want = (float)($context['amount'] ?? 0);
            if ($limitPct > 0 && $checkSum > 0 && $want > 0) {
                $maxPay = round($checkSum * $limitPct / 100.0, 2);
                if ($want > $maxPay + 0.009) {
                    return ['ok' => false, 'error' => t('certificates.err.use_limit', ['pct' => $limitPct]), 'cert' => $cert, 'balance' => $balance];
                }
            }
        } elseif ((int)$cert['kind'] === CertificatesRepository::KIND_ABON && $productIds !== []) {
            $remain = self::abonRemaining($cert);
            $matched = false;
            foreach ($productIds as $pid) {
                if (($remain[(int)$pid] ?? 0) > 0) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                return ['ok' => false, 'error' => t('certificates.err.abon_no_match'), 'cert' => $cert, 'balance' => $balance];
            }
        }

        return ['ok' => true, 'cert' => $cert, 'balance' => $balance];
    }

    /**
     * @return array{
     *   ok:bool,error?:string,event_id:int,client_id:int,cert_id:int,code:string,
     *   kind:int,balance:float,spend:float,partial_forbidden:bool,already_applied:bool,
     *   abon_goods?:array<int,int>,price_overrides?:array<int,float>
     * }
     */
    public static function quoteEvent(int $eventId, $codeOrId, ?array $event = null): array
    {
        $empty = [
            'ok' => false,
            'event_id' => $eventId,
            'client_id' => 0,
            'cert_id' => 0,
            'code' => '',
            'kind' => 0,
            'balance' => 0.0,
            'spend' => 0.0,
            'partial_forbidden' => false,
            'already_applied' => false,
        ];
        if ($eventId < 1) {
            $empty['error'] = t('certificates.err.event_not_found', ['id' => $eventId]);
            return $empty;
        }
        if ($event === null) {
            if (!class_exists('EventsRepository', false)) {
                $empty['error'] = t('certificates.err.event_not_found', ['id' => $eventId]);
                return $empty;
            }
            $event = EventsRepository::find($eventId);
        }
        if (!$event) {
            $empty['error'] = t('certificates.err.event_not_found', ['id' => $eventId]);
            return $empty;
        }

        $clientId = (int)($event['id_user'] ?? 0);
        $built = self::buildEventGoods($event);
        $bonusDiscount = (float)($event['bonus_discount'] ?? 0);
        $v = self::validate($codeOrId, $clientId, [
            'product_ids' => array_keys($built['goods']),
            'type_ids' => $built['type_ids'],
            'check_sum' => $built['sum'],
            'amount' => $built['sum'],
            'bonus_discount' => $bonusDiscount,
        ]);
        if (empty($v['ok'])) {
            $empty['error'] = (string)($v['error'] ?? t('certificates.err.apply_failed'));
            $empty['client_id'] = $clientId;
            if (!empty($v['cert'])) {
                $empty['cert_id'] = (int)$v['cert']['id'];
                $empty['code'] = (string)$v['cert']['code'];
                $empty['kind'] = (int)$v['cert']['kind'];
                $empty['balance'] = (float)($v['balance'] ?? $v['cert']['balance'] ?? 0);
            }
            return $empty;
        }

        /** @var array<string,mixed> $cert */
        $cert = $v['cert'];
        $balance = (float)$v['balance'];
        $certId = (int)$cert['id'];
        // One active spend per event (any certificate).
        $already = CertificateLedgerRepository::eventHasSpend($eventId);
        $partial = !empty($cert['partial_forbidden']);
        $spend = 0.0;
        $abonGoods = [];
        $priceOverrides = [];

        if ((int)$cert['kind'] === CertificatesRepository::KIND_ABON) {
            $remain = self::abonRemaining($cert);
            $found = false;
            foreach ($built['goods'] as $pid => $g) {
                $left = (int)($remain[$pid] ?? 0);
                if ($left < 1) {
                    continue;
                }
                $found = true;
                $abonGoods[$pid] = 1;
                $unit = 0.0;
                foreach ((array)$cert['content'] as $line) {
                    if ((int)($line['product_id'] ?? 0) === $pid) {
                        $unit = (float)($line['price'] ?? 0);
                        break;
                    }
                }
                $priceOverrides[$pid] = $unit;
                $spend += $unit;
            }
            if (!$found) {
                $empty['error'] = t('certificates.err.abon_no_match');
                $empty['client_id'] = $clientId;
                $empty['cert_id'] = $certId;
                $empty['code'] = (string)$cert['code'];
                $empty['kind'] = (int)$cert['kind'];
                $empty['balance'] = $balance;
                return $empty;
            }
            if ($partial) {
                $spend = $balance;
            }
            $spend = min($spend, $balance);
        } else {
            $want = (float)($built['sum']);
            if ($partial && $want < $balance) {
                $spend = $balance;
            } else {
                $spend = min($want, $balance);
            }
            $limitPct = (int)($cert['use_limit_pct'] ?? 0);
            if ($limitPct > 0 && $built['sum'] > 0) {
                $maxPay = round($built['sum'] * $limitPct / 100.0, 2);
                $spend = min($spend, $maxPay);
            }
        }

        return [
            'ok' => true,
            'event_id' => $eventId,
            'client_id' => $clientId,
            'cert_id' => $certId,
            'code' => (string)$cert['code'],
            'kind' => (int)$cert['kind'],
            'balance' => $balance,
            'spend' => round($spend, 2),
            'partial_forbidden' => $partial,
            'already_applied' => $already,
            'abon_goods' => $abonGoods,
            'price_overrides' => $priceOverrides,
        ];
    }

    /**
     * @return array{ok:bool,error?:string,spend:float,ledger_id:int,cert_id:int}
     */
    public static function applyEvent(int $eventId, $codeOrId, ?int $operatorId = null): array
    {
        $fail = static function (string $error) use ($eventId): array {
            return ['ok' => false, 'error' => $error, 'spend' => 0.0, 'ledger_id' => 0, 'cert_id' => 0, 'event_id' => $eventId];
        };
        if (!CertificatesRepository::tableReady() || !CertificateLedgerRepository::tableReady()) {
            return $fail(t('certificates.err.table'));
        }
        if (!class_exists('EventsRepository', false)) {
            return $fail(t('certificates.err.event_not_found', ['id' => $eventId]));
        }
        $event = EventsRepository::find($eventId);
        if (!$event) {
            return $fail(t('certificates.err.event_not_found', ['id' => $eventId]));
        }

        // Resolve only (no spend math yet) — full quote runs under row lock.
        $resolved = self::resolveCert($codeOrId);
        if ($resolved === null) {
            return $fail(t('certificates.err.not_found_code'));
        }
        $certId = (int)$resolved['id'];

        $op = $operatorId ?? (int)(Auth::userId() ?? 0);
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            // Lock event first (shared serialization), then certificate — avoids
            // double-apply across different certs and deadlocks with a fixed order.
            self::lockEvent($eventId);
            if (!self::lockCert($certId)) {
                throw new RuntimeException(t('certificates.err.not_found_code'));
            }
            $existing = CertificateLedgerRepository::eventActiveSpend($eventId);
            if ($existing !== null) {
                $pdo->commit();
                if ((int)$existing['id_cert'] !== $certId) {
                    return $fail(t('certificates.err.already_applied'));
                }
                return [
                    'ok' => true,
                    'spend' => (float)$existing['amount'],
                    'ledger_id' => (int)$existing['id'],
                    'cert_id' => $certId,
                    'event_id' => $eventId,
                    'idempotent' => true,
                ];
            }
            // Re-quote after lock so balance cannot race.
            $quote = self::quoteEvent($eventId, $certId, $event);
            if (empty($quote['ok'])) {
                throw new RuntimeException((string)($quote['error'] ?? t('certificates.err.apply_failed')));
            }
            if (!empty($quote['already_applied'])) {
                $existing = CertificateLedgerRepository::eventActiveSpend($eventId, $certId);
                $pdo->commit();
                return [
                    'ok' => true,
                    'spend' => (float)($existing['amount'] ?? $quote['spend']),
                    'ledger_id' => (int)($existing['id'] ?? 0),
                    'cert_id' => $certId,
                    'event_id' => $eventId,
                    'idempotent' => true,
                ];
            }
            $spend = (float)$quote['spend'];
            if ($spend <= 0) {
                throw new RuntimeException(t('certificates.err.nothing_to_spend'));
            }
            $balance = CertificateLedgerRepository::balance($certId);
            if ($spend > $balance + 0.009) {
                throw new RuntimeException(t('certificates.err.insufficient_balance'));
            }

            $cert = CertificatesRepository::find($certId);
            if ($cert === null) {
                throw new RuntimeException(t('certificates.err.not_found_code'));
            }
            if ((int)$cert['id_holder'] < 1 && (int)$quote['client_id'] > 0) {
                $pdo->prepare('UPDATE base_certificates SET ID_HOLDER = ?, DT_UPDATE = ? WHERE ID = ?')
                    ->execute([(int)$quote['client_id'], time(), $certId]);
                $cert['id_holder'] = (int)$quote['client_id'];
            }

            $ledgerId = CertificateLedgerRepository::insert([
                'id_cert' => $certId,
                'id_operator' => $op,
                'id_client' => (int)$quote['client_id'],
                'id_event' => $eventId,
                'direction' => CertificateLedgerRepository::DIR_DEBIT,
                'amount' => $spend,
                'reason' => CertificateLedgerRepository::REASON_SPEND,
                'descr' => t('certificates.ledger.spend_event', ['id' => $eventId]),
            ]);

            $contentUsed = (array)$cert['content_used'];
            if ((int)$cert['kind'] === CertificatesRepository::KIND_ABON) {
                $goods = (array)($quote['abon_goods'] ?? []);
                if ($goods !== []) {
                    $contentUsed[] = [
                        'ts' => (int)mktime(0, 0, 0),
                        'admin_id' => $op,
                        'goods' => $goods,
                    ];
                }
                self::applyPriceOverrides($eventId, (array)($quote['price_overrides'] ?? []));
            }

            $newUsed = round((float)$cert['face_value'] - CertificateLedgerRepository::balance($certId), 2);
            $expired = $newUsed + 0.009 >= (float)$cert['face_value'] ? 1 : (int)$cert['expired'];
            if (!empty($cert['partial_forbidden'])) {
                $expired = 1;
            }
            CertificatesRepository::updateUsage(
                $certId,
                max(0.0, min($newUsed, (float)$cert['face_value'])),
                $expired,
                $contentUsed,
                date('d.m.Y H:i:s') . ' event#' . $eventId . ' spend=' . $spend
            );

            $pdo->commit();
            return [
                'ok' => true,
                'spend' => $spend,
                'ledger_id' => $ledgerId,
                'cert_id' => $certId,
                'event_id' => $eventId,
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return $fail($e->getMessage());
        }
    }

    /**
     * Apply certificate on storefront checkout (order).
     *
     * @param list<array{id?:int,price?:float}> $lines
     * @return array{ok:bool,error?:string,spend:float,ledger_id:int,cert_id:int,summ_real:float}
     */
    public static function applyOrder(int $orderId, $codeOrId, int $clientId, array $lines, float $summ, ?int $operatorId = null): array
    {
        $fail = static function (string $error) use ($summ): array {
            return ['ok' => false, 'error' => $error, 'spend' => 0.0, 'ledger_id' => 0, 'cert_id' => 0, 'summ_real' => $summ];
        };
        if (!CertificatesRepository::tableReady() || !CertificateLedgerRepository::tableReady()) {
            return $fail(t('certificates.err.table'));
        }

        // Storefront / public: never resolve digit strings as IDs (code-only).
        $code = is_int($codeOrId) ? '' : trim((string)$codeOrId);
        if ($code === '') {
            return $fail(t('certificates.err.not_found_code'));
        }
        $resolved = CertificatesRepository::findByCode($code);
        if ($resolved === null) {
            return $fail(t('certificates.err.not_found_code'));
        }
        $certId = (int)$resolved['id'];

        $productIds = [];
        $typeIds = [];
        foreach ($lines as $line) {
            $pid = (int)($line['id'] ?? $line['product_id'] ?? 0);
            if ($pid < 1) {
                continue;
            }
            $productIds[] = $pid;
            if (class_exists('ProductsRepository', false)) {
                $p = ProductsRepository::find($pid);
                if ($p) {
                    $tid = (int)($p['type_id'] ?? 0);
                    if ($tid > 0) {
                        $typeIds[] = $tid;
                    }
                }
            }
        }
        $ctx = [
            'product_ids' => $productIds,
            'type_ids' => $typeIds,
            'check_sum' => $summ,
            'amount' => $summ,
            'allow_unbound_holder' => true,
        ];

        $op = $operatorId ?? 0;
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            // Lock order first, then certificate (fixed lock order).
            self::lockOrder($orderId);
            $existing = CertificateLedgerRepository::orderActiveSpend($orderId);
            if ($existing !== null) {
                $pdo->commit();
                $spent = (float)$existing['amount'];
                return [
                    'ok' => true,
                    'spend' => $spent,
                    'ledger_id' => (int)$existing['id'],
                    'cert_id' => (int)$existing['id_cert'],
                    'summ_real' => max(0.0, round($summ - $spent, 2)),
                    'idempotent' => true,
                ];
            }

            if (!self::lockCert($certId)) {
                throw new RuntimeException(t('certificates.err.not_found_code'));
            }
            // Re-check under locks (another worker may have spent while we waited).
            $existing = CertificateLedgerRepository::orderActiveSpend($orderId);
            if ($existing !== null) {
                $pdo->commit();
                $spent = (float)$existing['amount'];
                return [
                    'ok' => true,
                    'spend' => $spent,
                    'ledger_id' => (int)$existing['id'],
                    'cert_id' => (int)$existing['id_cert'],
                    'summ_real' => max(0.0, round($summ - $spent, 2)),
                    'idempotent' => true,
                ];
            }

            // Re-validate under lock (fresh balance / expiry / holder).
            $v = self::validate($certId, $clientId, $ctx);
            if (empty($v['ok'])) {
                throw new RuntimeException((string)($v['error'] ?? t('certificates.err.apply_failed')));
            }
            /** @var array<string,mixed> $cert */
            $cert = $v['cert'];
            $balance = CertificateLedgerRepository::balance($certId);
            $partial = !empty($cert['partial_forbidden']);
            $spend = $partial && $summ < $balance ? $balance : min($summ, $balance);
            $limitPct = (int)($cert['use_limit_pct'] ?? 0);
            if ($limitPct > 0 && $summ > 0) {
                $spend = min($spend, round($summ * $limitPct / 100.0, 2));
            }
            $spend = round($spend, 2);
            if ($spend <= 0) {
                throw new RuntimeException(t('certificates.err.nothing_to_spend'));
            }
            if ($spend > $balance + 0.009) {
                throw new RuntimeException(t('certificates.err.insufficient_balance'));
            }

            if ((int)$cert['id_holder'] < 1 && $clientId > 0) {
                $pdo->prepare('UPDATE base_certificates SET ID_HOLDER = ?, DT_UPDATE = ? WHERE ID = ?')
                    ->execute([$clientId, time(), $certId]);
            }
            $ledgerId = CertificateLedgerRepository::insert([
                'id_cert' => $certId,
                'id_operator' => $op,
                'id_client' => $clientId,
                'id_order' => $orderId,
                'direction' => CertificateLedgerRepository::DIR_DEBIT,
                'amount' => $spend,
                'reason' => CertificateLedgerRepository::REASON_SPEND,
                'descr' => t('certificates.ledger.spend_order', ['id' => $orderId]),
            ]);
            $newUsed = round((float)$cert['face_value'] - CertificateLedgerRepository::balance($certId), 2);
            $expired = $newUsed + 0.009 >= (float)$cert['face_value'] ? 1 : 0;
            if ($partial) {
                $expired = 1;
            }
            CertificatesRepository::updateUsage(
                $certId,
                max(0.0, min($newUsed, (float)$cert['face_value'])),
                $expired,
                (array)$cert['content_used'],
                date('d.m.Y H:i:s') . ' order#' . $orderId . ' spend=' . $spend
            );
            if (Database::tableExists('base_orders') && $orderId > 0) {
                $pdo->prepare('UPDATE base_orders SET ID_SERT = ?, SUMM_REAL = ?, DT_SAVE = ? WHERE ID = ?')
                    ->execute([$certId, max(0.0, round($summ - $spend, 2)), time(), $orderId]);
            }
            $pdo->commit();
            return [
                'ok' => true,
                'spend' => $spend,
                'ledger_id' => $ledgerId,
                'cert_id' => $certId,
                'summ_real' => max(0.0, round($summ - $spend, 2)),
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return $fail($e->getMessage());
        }
    }

    /** @return array{ok:bool,error?:string,ledger_id:int} */
    public static function issue(int $certId, ?int $operatorId = null): array
    {
        $cert = CertificatesRepository::find($certId);
        if ($cert === null) {
            return ['ok' => false, 'error' => t('certificates.err.not_found', ['id' => $certId]), 'ledger_id' => 0];
        }
        if (CertificateLedgerRepository::hasIssue($certId)) {
            return ['ok' => true, 'ledger_id' => 0];
        }
        $face = (float)$cert['face_value'];
        if ($face <= 0) {
            return ['ok' => false, 'error' => t('certificates.err.face_required'), 'ledger_id' => 0];
        }
        $id = CertificateLedgerRepository::insert([
            'id_cert' => $certId,
            'id_operator' => $operatorId ?? (int)$cert['id_operator'],
            'id_client' => (int)$cert['id_buyer'],
            'direction' => CertificateLedgerRepository::DIR_CREDIT,
            'amount' => $face,
            'reason' => CertificateLedgerRepository::REASON_ISSUE,
            'descr' => t('certificates.ledger.issue'),
            'dt_create' => (int)$cert['dt_create'] ?: time(),
        ]);
        return ['ok' => true, 'ledger_id' => $id];
    }

    /** @return array{ok:bool,error?:string,ledger_id:int} */
    public static function undoSpend(int $ledgerId, ?int $operatorId = null): array
    {
        if (!CertificateLedgerRepository::tableReady()) {
            return ['ok' => false, 'error' => t('certificates.err.ledger_table'), 'ledger_id' => 0];
        }
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $row = CertificateLedgerRepository::findForUpdate($ledgerId);
            if ($row === null) {
                throw new RuntimeException(t('certificates.err.ledger_not_found'));
            }
            if ((int)$row['direction'] >= 0 || (string)$row['reason'] !== CertificateLedgerRepository::REASON_SPEND) {
                throw new RuntimeException(t('certificates.err.undo_not_spend'));
            }
            $already = CertificateLedgerRepository::findUndoForSpend($ledgerId);
            if ($already !== null) {
                $pdo->commit();
                return ['ok' => true, 'ledger_id' => (int)$already['id'], 'idempotent' => true];
            }

            $certId = (int)$row['id_cert'];
            if (!self::lockCert($certId)) {
                throw new RuntimeException(t('certificates.err.not_found', ['id' => $certId]));
            }
            $cert = CertificatesRepository::find($certId);
            if ($cert === null) {
                throw new RuntimeException(t('certificates.err.not_found', ['id' => $certId]));
            }

            $undoId = CertificateLedgerRepository::insert([
                'id_cert' => $certId,
                'id_operator' => $operatorId ?? (int)(Auth::userId() ?? 0),
                'id_client' => (int)$row['id_client'],
                'id_event' => (int)$row['id_event'],
                'id_order' => (int)$row['id_order'],
                'id_ref' => $ledgerId,
                'direction' => CertificateLedgerRepository::DIR_CREDIT,
                'amount' => (float)$row['amount'],
                'reason' => CertificateLedgerRepository::REASON_UNDO,
                'descr' => t('certificates.ledger.undo', ['id' => $ledgerId]),
            ]);
            $bal = CertificateLedgerRepository::balance($certId);
            $face = (float)$cert['face_value'];
            $used = max(0.0, round($face - $bal, 2));
            $contentUsed = (array)$cert['content_used'];
            if ((int)$cert['kind'] === CertificatesRepository::KIND_ABON && $contentUsed !== []) {
                array_pop($contentUsed);
            }
            CertificatesRepository::updateUsage(
                $certId,
                $used,
                $used + 0.009 >= $face ? 1 : 0,
                $contentUsed
            );
            $pdo->commit();
            return ['ok' => true, 'ledger_id' => $undoId];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['ok' => false, 'error' => $e->getMessage(), 'ledger_id' => 0];
        }
    }

    /** @return array{ok:bool,error?:string,processed:int} */
    public static function expireDue(int $limit = 50): array
    {
        $ids = CertificatesRepository::dueForExpiry($limit);
        $n = 0;
        foreach ($ids as $id) {
            $r = self::expireOne($id);
            if (!empty($r['ok'])) {
                $n++;
            }
        }
        return ['ok' => true, 'processed' => $n];
    }

    /** @return array{ok:bool,error?:string,ledger_id:int} */
    public static function expireOne(int $certId, ?int $operatorId = null): array
    {
        if ($certId < 1 || !CertificatesRepository::tableReady()) {
            return ['ok' => false, 'error' => t('certificates.err.not_found', ['id' => $certId]), 'ledger_id' => 0];
        }
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            if (!self::lockCert($certId)) {
                throw new RuntimeException(t('certificates.err.not_found', ['id' => $certId]));
            }
            $cert = CertificatesRepository::find($certId);
            if ($cert === null) {
                throw new RuntimeException(t('certificates.err.not_found', ['id' => $certId]));
            }
            if (!empty($cert['expired'])) {
                $pdo->commit();
                return ['ok' => true, 'ledger_id' => 0, 'idempotent' => true];
            }
            $balance = CertificateLedgerRepository::balance($certId);
            $ledgerId = 0;
            if ($balance > 0) {
                $ledgerId = CertificateLedgerRepository::insert([
                    'id_cert' => $certId,
                    'id_operator' => $operatorId ?? 0,
                    'id_client' => (int)$cert['id_holder'] ?: (int)$cert['id_buyer'],
                    'direction' => CertificateLedgerRepository::DIR_DEBIT,
                    'amount' => $balance,
                    'reason' => CertificateLedgerRepository::REASON_EXPIRE,
                    'descr' => t('certificates.ledger.expire'),
                    'dt_create' => (int)$cert['dt_use_expire'] ?: time(),
                ]);
            }
            CertificatesRepository::updateUsage(
                $certId,
                (float)$cert['face_value'],
                1,
                (array)$cert['content_used'],
                date('d.m.Y H:i:s') . ' expired'
            );
            $pdo->commit();
            return ['ok' => true, 'ledger_id' => $ledgerId];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['ok' => false, 'error' => $e->getMessage(), 'ledger_id' => 0];
        }
    }

    /**
     * Resolve certificate.
     * - int → by ID (staff / internal only; callers must pass a real int, not a digit string)
     * - string → by CODE only (public inputs are always strings — never treat "7" as ID)
     *
     * @return array<string,mixed>|null
     */
    private static function resolveCert($codeOrId): ?array
    {
        if (is_int($codeOrId)) {
            return $codeOrId > 0 ? CertificatesRepository::find($codeOrId) : null;
        }
        $code = trim((string)$codeOrId);
        if ($code === '') {
            return null;
        }
        return CertificatesRepository::findByCode($code);
    }

    /** Lock certificate row; must be called inside an open transaction. */
    private static function lockCert(int $certId): bool
    {
        if ($certId < 1) {
            return false;
        }
        $st = Database::pdo()->prepare('SELECT ID FROM base_certificates WHERE ID = ? LIMIT 1 FOR UPDATE');
        $st->execute([$certId]);
        return (bool)$st->fetchColumn();
    }

    /** Lock event row for apply serialization (no-op if table/row missing). */
    private static function lockEvent(int $eventId): void
    {
        if ($eventId < 1 || !Database::tableExists('base_events')) {
            return;
        }
        $st = Database::pdo()->prepare('SELECT ID FROM base_events WHERE ID = ? LIMIT 1 FOR UPDATE');
        $st->execute([$eventId]);
        $st->fetchColumn();
    }

    /** Lock order row for apply serialization (no-op if table/row missing). */
    private static function lockOrder(int $orderId): void
    {
        if ($orderId < 1 || !Database::tableExists('base_orders')) {
            return;
        }
        $st = Database::pdo()->prepare('SELECT ID FROM base_orders WHERE ID = ? LIMIT 1 FOR UPDATE');
        $st->execute([$orderId]);
        $st->fetchColumn();
    }

    /**
     * @param list<int> $productIds
     * @param list<int> $typeIds
     * @param list<int> $allowedProducts
     * @param list<int> $allowedTypes
     */
    private static function goodsMatchRestrictions(
        array $productIds,
        array $typeIds,
        array $allowedProducts,
        array $allowedTypes
    ): bool {
        // Hard mode: every product must be allowed by product list and/or type list.
        if ($productIds === []) {
            return true;
        }
        foreach ($productIds as $pid) {
            $okProduct = $allowedProducts === [] || in_array($pid, $allowedProducts, true);
            $typeId = 0;
            if (class_exists('ProductsRepository', false)) {
                $p = ProductsRepository::find($pid);
                $typeId = $p ? (int)($p['type_id'] ?? 0) : 0;
            }
            if ($typeId < 1 && isset($typeIds[$pid])) {
                $typeId = (int)$typeIds[$pid];
            }
            $okType = $allowedTypes === [] || ($typeId > 0 && in_array($typeId, $allowedTypes, true));
            if ($allowedProducts !== [] && $allowedTypes !== []) {
                if (!$okProduct && !$okType) {
                    return false;
                }
            } elseif ($allowedProducts !== []) {
                if (!$okProduct) {
                    return false;
                }
            } elseif ($allowedTypes !== []) {
                if (!$okType) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * @param array<string,mixed> $cert
     * @return array<int,int> product_id => remaining qty
     */
    private static function abonRemaining(array $cert): array
    {
        $remain = [];
        foreach ((array)($cert['content'] ?? []) as $line) {
            $pid = (int)($line['product_id'] ?? 0);
            $qty = (int)($line['qty'] ?? 0);
            if ($pid > 0 && $qty > 0) {
                $remain[$pid] = ($remain[$pid] ?? 0) + $qty;
            }
        }
        foreach ((array)($cert['content_used'] ?? []) as $entry) {
            foreach ((array)($entry['goods'] ?? []) as $gid => $qty) {
                $gid = (int)$gid;
                if (isset($remain[$gid])) {
                    $remain[$gid] -= (int)$qty;
                }
            }
        }
        foreach ($remain as $gid => $qty) {
            if ($qty < 1) {
                unset($remain[$gid]);
            }
        }
        return $remain;
    }

    /**
     * @param array<string,mixed> $event
     * @return array{goods:array<int,array{name:string,price:float}>,type_ids:list<int>,sum:float}
     */
    private static function buildEventGoods(array $event): array
    {
        $ids = $event['content_ids'] ?? null;
        if (!is_array($ids)) {
            $ids = class_exists('EventsRepository', false)
                ? EventsRepository::parseContentIds((string)($event['content'] ?? ''))
                : [];
        }
        $priceMap = $event['content_price_map'] ?? null;
        if (!is_array($priceMap)) {
            $priceMap = class_exists('EventsRepository', false)
                ? EventsRepository::parseContentPrices((string)($event['content_price'] ?? ''))
                : [];
        }
        $goods = [];
        $typeIds = [];
        $sum = 0.0;
        foreach ($ids as $pid) {
            $pid = (int)$pid;
            if ($pid < 1) {
                continue;
            }
            $price = isset($priceMap[$pid]) ? (float)$priceMap[$pid] : 0.0;
            $name = '#' . $pid;
            if (class_exists('ProductsRepository', false)) {
                $product = ProductsRepository::find($pid);
                if ($product) {
                    $name = (string)($product['name'] ?? $name);
                    $tid = (int)($product['type_id'] ?? 0);
                    if ($tid > 0) {
                        $typeIds[] = $tid;
                    }
                    if ($price <= 0) {
                        $price = (float)($product['price'] ?? 0);
                    }
                }
            }
            $goods[$pid] = ['name' => $name, 'price' => $price];
            $sum += $price;
        }
        return ['goods' => $goods, 'type_ids' => array_values(array_unique($typeIds)), 'sum' => $sum];
    }

    /** @param array<int,float> $overrides */
    private static function applyPriceOverrides(int $eventId, array $overrides): void
    {
        if ($eventId < 1 || $overrides === [] || !Database::tableExists('base_events')) {
            return;
        }
        if (!class_exists('EventsRepository', false)) {
            return;
        }
        $event = EventsRepository::find($eventId);
        if (!$event) {
            return;
        }
        $map = EventsRepository::parseContentPrices((string)($event['content_price'] ?? ''));
        foreach ($overrides as $pid => $price) {
            $map[(int)$pid] = (float)$price;
        }
        $parts = [];
        foreach ($map as $pid => $price) {
            $parts[] = 'p' . (int)$pid . ':' . $price;
        }
        $contentPrice = implode(',', $parts);
        $real = 0.0;
        foreach ($map as $price) {
            $real += (float)$price;
        }
        Database::pdo()->prepare(
            'UPDATE base_events SET CONTENT_PRICE = ?, REAL_SUMM = ?, DTUPDATE = ? WHERE ID = ?'
        )->execute([$contentPrice, round($real, 2), time(), $eventId]);
    }
}
