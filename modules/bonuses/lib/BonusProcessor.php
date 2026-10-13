<?php
declare(strict_types=1);

/**
 * Client bonus settlement for calendar events.
 * Allocates spend across line items, then accrues on cash remainder.
 */
final class BonusProcessor
{
    /** Accrual expiry window (~6 months). */
    public const ACCRUE_TTL_SEC = 60 * 60 * 24 * 30 * 6;

    /**
     * Spend requested discount preferring low accrual-% lines first,
     * then accrue status×type % on remaining cash.
     *
     * @param array<int|string,array{price:float,bonus_in_proc:float,bonus_out_proc:float,name?:string}> $goodArray
     * @return array{
     *   totalBonusIn:float,totalBonusOut:float,totalToPay:float,bonusAvg:float,
     *   lines:array<int|string,array<string,mixed>>
     * }
     */
    public static function allocate(array $goodArray, float $bonusDiscount = 0.0, float $bonusAdd = 0.0): array
    {
        uasort($goodArray, static function ($a, $b): int {
            return ((float)($a['bonus_in_proc'] ?? 0) <=> (float)($b['bonus_in_proc'] ?? 0));
        });

        $discountLeft = max(0.0, $bonusDiscount);
        $totalBonusIn = 0.0;
        $totalBonusOut = 0.0;
        $totalToPay = 0.0;
        $bonusAvg = 0.0;
        $bonusAvgCount = 0;

        foreach ($goodArray as $gid => $data) {
            $procentOut = (float)($data['bonus_out_proc'] ?? 0);
            $procentIn = (float)($data['bonus_in_proc'] ?? 0) * $bonusAdd / 100.0;
            $summSrc = (float)($data['price'] ?? 0);
            $summAvailToPayByBonus = round($procentOut * $summSrc / 100.0);
            $summPaidByBonus = $summAvailToPayByBonus >= $discountLeft
                ? $discountLeft
                : $summAvailToPayByBonus;
            $discountLeft -= $summPaidByBonus;
            $summLeftToPay = $summSrc - $summPaidByBonus;
            $bonusIn = (float)floor($summLeftToPay * $procentIn / 100.0);

            if ($procentIn > 0) {
                $bonusAvg += $procentIn;
                $bonusAvgCount++;
            }

            $goodArray[$gid]['summAvailToPayByBonus'] = $summAvailToPayByBonus;
            $goodArray[$gid]['summPaidByBonus'] = $summPaidByBonus;
            $goodArray[$gid]['summLeftToPay'] = $summLeftToPay;
            $goodArray[$gid]['bonus_in'] = $bonusIn;

            $totalBonusIn += $bonusIn;
            $totalBonusOut += $summPaidByBonus;
            $totalToPay += $summLeftToPay;
        }

        // Free event caused by bonuses → leave at least 1 ₽ cash.
        if ($totalToPay < 1 && $totalBonusOut > 0) {
            $totalToPay = 1.0;
            $totalBonusOut -= 1.0;
        }

        $bonusAvg = $bonusAvgCount > 0 ? (float)floor($bonusAvg / $bonusAvgCount) : 0.0;

        return [
            'totalBonusIn' => $totalBonusIn,
            'totalBonusOut' => $totalBonusOut,
            'totalToPay' => $totalToPay,
            'bonusAvg' => $bonusAvg,
            'lines' => $goodArray,
        ];
    }

    /** @deprecated Use allocate() */
    public static function bonusAnal(array $goodArray, float $bonusDiscount = 0.0, float $bonusAdd = 0.0): array
    {
        return self::allocate($goodArray, $bonusDiscount, $bonusAdd);
    }

    /**
     * @param array<string,mixed>|null $event Preloaded EventsRepository row
     * @return array{
     *   ok:bool,error?:string,event_id:int,client_id:int,balance:float,
     *   bonus_use:bool,requested_discount:float,content_sum:float,
     *   spend:float,accrue:float,to_pay:float,bonus_avg:float,already_applied:bool,
     *   bonus_add_status:float
     * }
     */
    public static function quote(int $eventId, ?array $event = null): array
    {
        $empty = [
            'ok' => false,
            'event_id' => $eventId,
            'client_id' => 0,
            'balance' => 0.0,
            'bonus_use' => false,
            'requested_discount' => 0.0,
            'content_sum' => 0.0,
            'spend' => 0.0,
            'accrue' => 0.0,
            'to_pay' => 0.0,
            'bonus_avg' => 0.0,
            'already_applied' => false,
            'bonus_add_status' => 0.0,
        ];

        if ($eventId < 1) {
            $empty['error'] = t('bonuses.err.event_not_found', ['id' => $eventId]);
            return $empty;
        }
        if ($event === null) {
            if (!class_exists('EventsRepository', false)) {
                $empty['error'] = t('bonuses.err.event_not_found', ['id' => $eventId]);
                return $empty;
            }
            $event = EventsRepository::find($eventId);
        }
        if (!$event) {
            $empty['error'] = t('bonuses.err.event_not_found', ['id' => $eventId]);
            return $empty;
        }

        $clientId = (int)($event['id_user'] ?? 0);
        $balance = BonusMovesRepository::balance($clientId);
        $bonusUse = !empty($event['bonus_use']);
        $requested = max(0.0, (float)($event['bonus_discount'] ?? 0));
        if ($bonusUse && $requested <= 0 && $balance > 0) {
            $requested = $balance;
        }
        if (!$bonusUse) {
            $requested = 0.0;
        }

        $built = self::buildGoods($event);
        $bonusAdd = self::clientBonusAddPercent($clientId);
        $anal = self::allocate($built['goods'], $requested, $bonusAdd);

        return [
            'ok' => true,
            'event_id' => $eventId,
            'client_id' => $clientId,
            'balance' => $balance,
            'bonus_use' => $bonusUse,
            'requested_discount' => $requested,
            'content_sum' => $built['sum'],
            'spend' => (float)$anal['totalBonusOut'],
            'accrue' => (float)$anal['totalBonusIn'],
            'to_pay' => (float)$anal['totalToPay'],
            'bonus_avg' => (float)$anal['bonusAvg'],
            'already_applied' => BonusMovesRepository::eventHasSettlement($eventId),
            'bonus_add_status' => $bonusAdd,
        ];
    }

    /**
     * @return array{ok:bool,error?:string,spend:float,accrue:float,to_pay:float,debit_id:int,credit_id:int}
     */
    public static function applyForEvent(int $eventId): array
    {
        $fail = static function (string $error) use ($eventId): array {
            return [
                'ok' => false,
                'error' => $error,
                'spend' => 0.0,
                'accrue' => 0.0,
                'to_pay' => 0.0,
                'debit_id' => 0,
                'credit_id' => 0,
                'event_id' => $eventId,
            ];
        };

        if (!BonusMovesRepository::tableReady()) {
            return $fail(t('bonuses.err.table'));
        }
        if (!class_exists('EventsRepository', false)) {
            return $fail(t('bonuses.err.event_not_found', ['id' => $eventId]));
        }
        $event = EventsRepository::find($eventId);
        if (!$event) {
            return $fail(t('bonuses.err.event_not_found', ['id' => $eventId]));
        }
        if (BonusMovesRepository::eventHasSettlement($eventId)) {
            return $fail(t('bonuses.err.already_applied'));
        }

        $clientId = (int)($event['id_user'] ?? 0);
        if ($clientId < 1) {
            return $fail(t('bonuses.err.no_client'));
        }

        $quote = self::quote($eventId, $event);
        if (empty($quote['ok'])) {
            return $fail((string)($quote['error'] ?? t('bonuses.err.apply_failed')));
        }

        $spend = (float)$quote['spend'];
        $accrue = (float)$quote['accrue'];
        $toPay = (float)$quote['to_pay'];
        $contentSum = (float)$quote['content_sum'];
        $bonusUse = !empty($quote['bonus_use']);
        $bonusAvg = (float)$quote['bonus_avg'];
        $now = time();

        $debitId = 0;
        $creditId = 0;

        if ($bonusUse && $spend > 0) {
            $debitId = BonusMovesRepository::create([
                'client_id' => $clientId,
                'direction' => -1,
                'amount' => $spend,
                'kind' => BonusMovesRepository::KIND_EVENT_SPEND,
                'ref_type' => BonusMovesRepository::REF_EVENT,
                'ref_id' => $eventId,
                'created_at' => $now,
                'expires_at' => null,
                'note' => t('bonuses.note.event_spend', [
                    'event' => $eventId,
                    'sum' => (string)(int)round($contentSum),
                ]),
                'dismissed' => 0,
            ]);
        }

        if ($accrue > 0) {
            $creditId = BonusMovesRepository::create([
                'client_id' => $clientId,
                'direction' => 1,
                'amount' => $accrue,
                'kind' => BonusMovesRepository::KIND_EVENT_ACCRUE,
                'ref_type' => BonusMovesRepository::REF_EVENT,
                'ref_id' => $eventId,
                'created_at' => $now,
                'expires_at' => $now + self::ACCRUE_TTL_SEC,
                'note' => t('bonuses.note.event_accrue', [
                    'event' => $eventId,
                    'pay' => (string)(int)round($toPay),
                    'pct' => (string)(int)round($bonusAvg),
                ]),
                'dismissed' => 0,
            ]);
        }

        self::updateEventMoney($eventId, $spend, max(0.0, $contentSum - $spend));

        return [
            'ok' => true,
            'event_id' => $eventId,
            'spend' => $spend,
            'accrue' => $accrue,
            'to_pay' => $toPay,
            'debit_id' => $debitId,
            'credit_id' => $creditId,
        ];
    }

    public static function clientBonusAddPercent(int $clientId): float
    {
        if ($clientId < 1 || !class_exists('ClientsRepository', false)) {
            return 0.0;
        }
        $client = ClientsRepository::find($clientId);
        if (!$client) {
            return 0.0;
        }
        $statusId = (int)($client['status_id'] ?? 0);
        if ($statusId < 1 || !class_exists('ClientStatusesRepository', false)) {
            return 0.0;
        }
        $status = ClientStatusesRepository::find($statusId);
        if (!$status) {
            return 0.0;
        }
        return max(0.0, (float)($status['bonus'] ?? 0));
    }

    /**
     * @param array<string,mixed> $event
     * @return array{goods:array<int,array{name:string,price:float,bonus_in_proc:float,bonus_out_proc:float}>,sum:float}
     */
    public static function buildGoods(array $event): array
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
        $sum = 0.0;
        foreach ($ids as $pid) {
            $pid = (int)$pid;
            if ($pid < 1) {
                continue;
            }
            $price = isset($priceMap[$pid]) ? (float)$priceMap[$pid] : 0.0;
            $name = '#' . $pid;
            $typeId = 0;
            if (class_exists('ProductsRepository', false)) {
                $product = ProductsRepository::find($pid);
                if ($product) {
                    $name = (string)($product['name'] ?? $name);
                    $typeId = (int)($product['type_id'] ?? 0);
                    if ($price <= 0 && isset($product['price'])) {
                        $price = (float)$product['price'];
                    }
                }
            }
            $in = 0.0;
            $out = 0.0;
            if ($typeId > 0 && class_exists('ProcedureTypesRepository', false)) {
                $type = ProcedureTypesRepository::find($typeId);
                if ($type) {
                    $in = (float)($type['bonus_procent'] ?? 0);
                    $out = (float)($type['bonus_procent_out'] ?? 0);
                }
            }
            $goods[$pid] = [
                'name' => $name,
                'price' => $price,
                'bonus_in_proc' => $in,
                'bonus_out_proc' => $out,
            ];
            $sum += $price;
        }

        return ['goods' => $goods, 'sum' => $sum];
    }

    private static function updateEventMoney(int $eventId, float $bonusDiscount, float $realSumm): void
    {
        if ($eventId < 1 || !Database::tableExists('base_events')) {
            return;
        }
        $st = Database::pdo()->prepare(
            'UPDATE base_events SET BONUS_DISCOUNT = ?, REAL_SUMM = ?, DTUPDATE = ? WHERE ID = ?'
        );
        $st->execute([$bonusDiscount, $realSumm, time(), $eventId]);
    }
}
