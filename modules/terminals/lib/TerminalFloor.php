<?php
declare(strict_types=1);

/**
 * /t floor shell: parse → claim → catalog/slots → commit/cancel.
 * Frozen query: terminalid (= device CODE, or numeric ID), braceletid, token
 * (+ adminId, gids, getTime, doStart, disable1C).
 */
final class TerminalFloor
{
    /**
     * @return array<string,mixed>
     */
    public static function process(): array
    {
        $q = self::parseInput();
        if (!empty($q['need_redirect']) || ($q['error'] ?? '') !== '') {
            return $q;
        }
        $mutateIgnoredMsg = !empty($q['mutate_ignored'])
            ? t('terminals.t.warn.get_mutate')
            : '';

        $terminal = self::resolveTerminal((string)$q['terminalid']);
        if ($terminal === null || (int)($terminal['enabled'] ?? 0) < 1) {
            return self::err($q, t('terminals.t.err.terminal'));
        }
        // Keep public query as CODE (legacy phones) when known.
        if ((string)($terminal['code'] ?? '') !== '') {
            $q['terminalid'] = (string)$terminal['code'];
        }

        $bracelet = trim((string)$q['braceletid']);
        $staff = StaffBraceletsRepository::tableReady()
            ? StaffBraceletsRepository::findByUid($bracelet)
            : null;

        // Oper: shopusers with 1C_BRACELET+ID_USER = employee client (claim + guest floor + history).
        // Pure LikeSpa staff NFC (no linked client) → short staff_claim ack only.
        $employeeClient = null;
        $staffUserId = 0;
        if ($staff !== null) {
            $adminId = (int)($staff['user_id'] ?? 0);
            $staffUserId = $adminId;
            $claim = TerminalsRepository::claimStaffDay((int)$terminal['id'], $adminId);
            if (empty($claim['ok'])) {
                return self::err($q, (string)($claim['error'] ?? t('terminals.t.err.claim')));
            }
            $terminal = TerminalsRepository::find((int)$terminal['id'], false) ?? $terminal;
            if ($adminId > 0 && class_exists(ClientsRepository::class, false)) {
                $employeeClient = ClientsRepository::findByStaffUserId($adminId);
            }
            if ($employeeClient === null) {
                return [
                    'ok' => true,
                    'mode' => 'staff_claim',
                    'terminal' => $terminal,
                    'staff_id' => $adminId,
                    'message' => t('terminals.t.staff_claimed'),
                    'query' => $q,
                    'catalog' => [],
                    'slots' => [],
                ];
            }
            if ($staffUserId < 1) {
                $staffUserId = max(0, (int)($employeeClient['staff_user_id'] ?? 0));
            }
        }

        if ($employeeClient === null && !TerminalsRepository::hasValidStaffClaim($terminal)) {
            return self::err($q, t('terminals.t.err.need_staff'));
        }

        // Employee floor: oper id1c=999 / balance 9999 — no live 1C. Else stub only when 1c_enabled=0.
        $disable1c = $employeeClient !== null ? true : !TerminalsConfig::onecEnabled();
        OnecClient::resetRequestCount();
        if (!empty($q['resetCache']) && isset($_SESSION['t_guest_cache'][(string)$q['token']])) {
            unset($_SESSION['t_guest_cache'][(string)$q['token']]);
        }
        $cacheTtl = 0;
        $clientId = 0;
        if ($employeeClient !== null) {
            $clientId = (int)($employeeClient['id'] ?? 0);
            $empName = trim((string)($employeeClient['name'] ?? ''));
            if ($empName === '') {
                $empName = trim((string)($employeeClient['tel'] ?? ''));
            }
            $guest = [
                'ok' => true,
                'stub' => true,
                'employee' => true,
                'client_id' => '999',
                'name' => $empName !== '' ? $empName : t('terminals.t.employee_guest'),
                'ticket_name' => t('terminals.t.employee_ticket'),
                'time' => 12 * 3600,
                'balance' => 9999.0,
                'phone' => trim((string)($employeeClient['tel'] ?? '')),
                'data' => [],
            ];
        } else {
            $guest = self::guestCached((string)$q['token'], $bracelet, $disable1c, $cacheTtl);
            if (empty($guest['ok'])) {
                return self::err($q, (string)($guest['error'] ?? t('terminals.t.err.bracelet')));
            }
            if (class_exists(ClientOnecBindingsRepository::class, false) && ClientOnecBindingsRepository::tableReady()) {
                $bind = ClientOnecBindingsRepository::findByOnecId((string)($guest['client_id'] ?? ''));
                if ($bind !== null) {
                    $clientId = (int)($bind['client_id'] ?? 0);
                }
            }
        }

        $message = $mutateIgnoredMsg;
        $error = '';
        $displayName = (string)($guest['name'] ?? '');
        if ($clientId > 0 && class_exists(ClientsRepository::class, false)) {
            $clientRow = ClientsRepository::find($clientId);
            if ($clientRow !== null && trim((string)($clientRow['name'] ?? '')) !== '') {
                $displayName = (string)$clientRow['name'];
            }
        }
        if (!empty($q['userNameSent']) && $clientId > 0 && Database::tableExists('base_clients')) {
            $newName = trim((string)$q['userNameSent']);
            if ($newName !== '') {
                Database::pdo()->prepare('UPDATE base_clients SET name = ? WHERE id = ?')
                    ->execute([mb_substr($newName, 0, 200), $clientId]);
                $displayName = $newName;
                $message = t('terminals.t.name_saved');
                $q['rename'] = false;
            }
        }
        $guest['display_name'] = $displayName;

        $balance = (float)($guest['balance'] ?? 0);
        $forceLimit = !empty($q['forceLimit']);
        if ($forceLimit) {
            $balance += 7999.0;
        }
        $guest['balance'] = $balance;
        $guest['force_limit'] = $forceLimit;

        if (!empty($q['unlinkClient']) && $clientId > 0
            && class_exists(ClientOnecBindingsRepository::class, false)) {
            ClientOnecBindingsRepository::unbind($clientId);
            $clientId = 0;
            $message = t('terminals.t.client_unlinked');
        }

        // Oper/likespa /t: write term-scan as soon as NFC+1C+claimed admin are known
        // (before NUM / phone / name). Promoter discount activation must not wait for CRM.
        $claimedAdminId = (int)($terminal['id_admin'] ?? 0);
        self::maybeRegisterTermScanEarly(
            $q,
            $terminal,
            $guest,
            $clientId,
            $bracelet,
            $claimedAdminId,
            $employeeClient !== null
        );

        $numState = TerminalFloorHome::handleBraceletNum(
            $terminal,
            $bracelet,
            (string)($q['braceletNum'] ?? '')
        );
        if (!empty($numState['need_prompt'])) {
            return self::withHomeExtras([
                'ok' => true,
                'mode' => 'need_bracelet_num',
                'terminal' => $terminal,
                'guest' => $guest,
                'client_id' => $clientId,
                'admin_id' => (int)($terminal['id_admin'] ?? 0),
                'bracelet_num' => (string)$numState['num'],
                'message' => $message,
                'error' => $error !== '' ? $error : (string)($numState['error'] ?? ''),
                'query' => $q,
                'onec_stub' => $disable1c,
            ], $terminal, $guest, $clientId, $bracelet, $disable1c, $cacheTtl);
        }

        // Claimed staff (ID_ADMIN) ≠ performing masseur. Masseur is only query adminId.
        $claimedAdminId = (int)($terminal['id_admin'] ?? $claimedAdminId);
        $operatorId = (int)($q['adminId'] ?? 0);
        $gids = self::parseGids((string)($q['gids'] ?? ''));
        $categoryId = (int)($q['category'] ?? 0);
        $getTime = (string)($q['getTime'] ?? '');
        // Legacy: getTime=1 → pick slot UI; getTime=<unix> → commit. Never treat "1" as a timestamp.
        $commitTs = 0;
        $pickSlot = false;
        if (ctype_digit($getTime)) {
            $ts = (int)$getTime;
            if ($ts >= 1000000000) {
                $commitTs = $ts;
            } elseif ($ts === 1) {
                $pickSlot = true;
            }
        }
        $allowExecutors = self::parseGids((string)($terminal['executors'] ?? ''));

        // Soft-lock while specialist is selected; clear when returning to catalog (no adminId).
        if ($operatorId > 0) {
            $busyMapProbe = TerminalsRepository::busyExecutors((int)$terminal['id']);
            if (!isset($busyMapProbe[$operatorId])) {
                TerminalsRepository::touchExecutorLock((int)$terminal['id'], $operatorId, 50);
            }
        } else {
            TerminalsRepository::clearExecutorLock((int)$terminal['id']);
        }

        if ((string)($q['doStart'] ?? '') === '2') {
            $cancelFlow = self::handleCancelFlow(
                $q,
                $clientId,
                $disable1c,
                (string)$q['token']
            );
            if (($cancelFlow['mode'] ?? '') === 'cancel_confirm') {
                return self::withHomeExtras([
                    'ok' => true,
                    'mode' => 'cancel_confirm',
                    'terminal' => $terminal,
                    'guest' => $guest,
                    'client_id' => $clientId,
                    'admin_id' => $claimedAdminId,
                    'bracelet_num' => (string)$numState['num'],
                    'cancel' => $cancelFlow['cancel'] ?? [],
                    'message' => $message,
                    'error' => (string)($cancelFlow['error'] ?? ''),
                    'query' => $q,
                    'onec_stub' => $disable1c,
                ], $terminal, $guest, $clientId, $bracelet, $disable1c, $cacheTtl);
            }
            if (!empty($cancelFlow['ok']) || (($cancelFlow['results'] ?? []) !== [])) {
                return self::withHomeExtras([
                    'ok' => !empty($cancelFlow['ok']),
                    'mode' => !empty($cancelFlow['ok']) ? 'cancelled' : 'cancel_failed',
                    'terminal' => $terminal,
                    'guest' => $guest,
                    'client_id' => $clientId,
                    'admin_id' => $claimedAdminId,
                    'bracelet_num' => (string)$numState['num'],
                    'message' => !empty($cancelFlow['ok']) ? t('terminals.t.cancelled') : '',
                    'error' => (string)($cancelFlow['error'] ?? ''),
                    'results' => is_array($cancelFlow['results'] ?? null) ? $cancelFlow['results'] : [],
                    'query' => $q,
                    'onec_stub' => $disable1c,
                ], $terminal, $guest, $clientId, $bracelet, $disable1c, $cacheTtl);
            }
            if (($cancelFlow['error'] ?? '') !== '') {
                $error = (string)$cancelFlow['error'];
            }
        }

        // Guest identity (oper): phone → find/create client + bind 1C/stub id → name if empty.
        $identity = self::ensureGuestIdentity(
            $clientId,
            $guest,
            (string)($q['clientPhoneSent'] ?? ''),
            (string)($q['userNameSent'] ?? ''),
            !empty($q['rename'])
        );
        $clientId = (int)($identity['client_id'] ?? $clientId);
        if (($identity['display_name'] ?? '') !== '') {
            $guest['display_name'] = (string)$identity['display_name'];
            $displayName = (string)$identity['display_name'];
        }
        if (($identity['message'] ?? '') !== '') {
            $message = (string)$identity['message'];
        }
        if (($identity['mode'] ?? '') === 'need_phone' || ($identity['mode'] ?? '') === 'need_name') {
            // Empty input for stub/placeholder names — admin types without clearing first.
            if (($identity['mode'] ?? '') === 'need_name') {
                $dn = (string)($identity['display_name'] ?? '');
                $guest['display_name'] = $dn;
                $guest['name'] = $dn;
            }
            return self::withHomeExtras([
                'ok' => true,
                'mode' => (string)$identity['mode'],
                'terminal' => $terminal,
                'guest' => $guest,
                'client_id' => $clientId,
                'admin_id' => (int)($terminal['id_admin'] ?? 0),
                'bracelet_num' => (string)$numState['num'],
                'phone_hint' => (string)($identity['phone_hint'] ?? ''),
                'message' => $message,
                'error' => $error !== '' ? $error : (string)($identity['error'] ?? ''),
                'query' => $q,
                'onec_stub' => $disable1c,
            ], $terminal, $guest, $clientId, $bracelet, $disable1c, $cacheTtl);
        }

        // Guest known → orders (etc.) may fulfill open advance bookings for this client.
        self::emitGuestIdentified(
            $clientId,
            $terminal,
            $bracelet,
            (string)$numState['num'],
            $claimedAdminId,
            (string)$q['token']
        );

        // Oper: enrich early term-scan with phone / local client id after auth.
        self::maybeSupplementTermScan($q, $guest, $clientId, $bracelet, $claimedAdminId);

        $discount = self::resolveDiscountContext(
            $terminal,
            $guest,
            $clientId,
            $bracelet,
            $claimedAdminId,
            !empty($q['flyerOn']),
            $categoryId,
            $gids
        );
        $flyerPct = (float)($discount['flyer_pct'] ?? 0);
        $flyerEnabledSys = !empty($discount['flyer_enabled']);
        $flyerDiscountCfg = (float)($discount['flyer_cfg'] ?? 0);
        $promoActive = !empty($discount['promo_active']);
        $discountCtx = [
            'base_pct' => (float)($discount['base_pct'] ?? 0),
            'promo_active' => $promoActive,
            'type_limits' => is_array($discount['type_limits'] ?? null) ? $discount['type_limits'] : [],
        ];

        if ($commitTs > 0 && $gids !== [] && $operatorId > 0) {
            $results = [];
            $cursor = $commitTs;
            // TOKEN is VARCHAR(32): first item keeps scan token; rest get md5 (32 hex).
            $baseToken = substr((string)$q['token'], 0, 32);
            $cartProducts = class_exists(BookingAvailability::class, false)
                ? BookingAvailability::loadProducts($gids)
                : [];
            $cartProbe = ($cartProducts !== [] && class_exists(BookingSlots::class, false))
                ? BookingSlots::check(
                    $cartProducts,
                    $operatorId,
                    [],
                    $commitTs,
                    $commitTs + 86400
                )
                : ['timeChill' => 0, 'timeText' => 0.0, 'timeChillText' => 0.0];
            $timeChill = (int)($cartProbe['timeChill'] ?? 0);
            $lastIdx = count($gids) - 1;
            foreach ($gids as $idx => $gid) {
                $itemToken = $idx === 0
                    ? $baseToken
                    : md5($baseToken . '#' . $gid);
                $product = class_exists(ProductsRepository::class, false)
                    ? ProductsRepository::find($gid)
                    : null;
                $listPrice = 0.0;
                $typeId = 0;
                if ($product !== null) {
                    $listPrice = (float)($product['price'] ?? 0);
                    if ($listPrice <= 0) {
                        $listPrice = (float)($product['price3'] ?? 0);
                    }
                    $typeId = max(0, (int)($product['type_id'] ?? $product['type'] ?? 0));
                }
                $priced = TerminalFloorCatalog::priceForProduct(
                    $listPrice,
                    $typeId,
                    (float)$discountCtx['base_pct'],
                    !empty($discountCtx['promo_active']),
                    is_array($discountCtx['type_limits']) ? $discountCtx['type_limits'] : []
                );
                $bookIn = [
                    'product_id' => $gid,
                    'operator_id' => $operatorId,
                    'admin_id' => $claimedAdminId > 0 ? $claimedAdminId : $operatorId,
                    'terminal_id' => (int)$terminal['id'],
                    'dt_start' => $cursor,
                    'client_id' => $clientId,
                    'onec_client_id' => (string)($guest['client_id'] ?? ''),
                    'onec_bracelet' => $bracelet,
                    'token' => $itemToken,
                    'disable_1c' => $disable1c,
                    'lead_sec' => TerminalsConfig::leadSec(),
                    'skip_chill' => true,
                    'price' => (float)$priced['price'],
                    'price_list' => $listPrice,
                ];
                if ($employeeClient !== null && $staffUserId > 0) {
                    $bookIn['employee'] = true;
                    $bookIn['staff_user_id'] = $staffUserId;
                }
                // One chill after the whole cart (legacy parity).
                if ($idx === $lastIdx && $timeChill > 0) {
                    $bookIn['skip_chill'] = false;
                    $bookIn['time_chill'] = $timeChill;
                }
                $book = TerminalBooking::book($bookIn);
                $results[] = $book;
                if (empty($book['ok'])) {
                    $bookErr = (string)($book['error'] ?? '');
                    if ($bookErr === 'token already used') {
                        $bookErr = t('terminals.t.scan_again');
                    } elseif ($bookErr === '') {
                        $bookErr = t('terminals.t.err.book');
                    }
                    return self::withHomeExtras([
                        'ok' => false,
                        'mode' => 'book',
                        'terminal' => $terminal,
                        'guest' => $guest,
                        'client_id' => $clientId,
                        'admin_id' => $claimedAdminId,
                        'error' => $bookErr,
                        'query' => $q,
                        'results' => $results,
                        'onec_stub' => $disable1c,
                    ], $terminal, $guest, $clientId, $bracelet, $disable1c, $cacheTtl);
                }
                if ($product === null && class_exists(ProductsRepository::class, false)) {
                    $product = ProductsRepository::find($gid);
                }
                $timeNet = max(0, (int)($product['time_net'] ?? 0));
                $timeSec = max(0, (int)($product['time_sec'] ?? 0));
                if ($timeNet < 1) {
                    $timeNet = $timeSec > 0 ? $timeSec : 3600;
                }
                $cursor += max(300, $timeNet);
            }
            return self::withHomeExtras([
                'ok' => true,
                'mode' => 'booked',
                'terminal' => $terminal,
                'guest' => $guest,
                'client_id' => $clientId,
                'admin_id' => $claimedAdminId,
                'message' => t('terminals.t.booked'),
                'query' => $q,
                'results' => $results,
                'cart_time_text' => (float)($cartProbe['timeText'] ?? 0),
                'cart_chill_text' => (float)($cartProbe['timeChillText'] ?? 0),
                'onec_stub' => $disable1c,
            ], $terminal, $guest, $clientId, $bracelet, $disable1c, $cacheTtl);
        }

        $catalog = self::buildCatalog($terminal);
        $catalogGroups = class_exists(BookingCatalog::class, false)
            ? BookingCatalog::groupByCategory($catalog)
            : [];
        $categoryProducts = [];
        $categoryName = '';
        if ($categoryId > 0) {
            $categoryProducts = TerminalFloorCatalog::productsForCategory($catalog, $categoryId, $gids);
            if (class_exists(CategoriesRepository::class, false)) {
                $cat = CategoriesRepository::find($categoryId);
                $categoryName = (string)($cat['name'] ?? ('#' . $categoryId));
            }
            if ($categoryName === '') {
                foreach ($catalogGroups as $group) {
                    if ((int)($group['id'] ?? 0) === $categoryId) {
                        $categoryName = (string)($group['name'] ?? '');
                        break;
                    }
                }
            }
        }
        $slots = [];
        $operators = [];
        $slotTimeText = 0.0;
        $slotChillText = 0.0;
        $date = date('Y-m-d');
        $busyMap = TerminalsRepository::busyExecutors((int)$terminal['id']);
        $busy = array_keys($busyMap);

        if ($pickSlot && $gids !== [] && $operatorId > 0 && class_exists(BookingAvailability::class, false)) {
            try {
                $avail = BookingAvailability::forDay(
                    $date,
                    $gids,
                    $operatorId,
                    null,
                    false,
                    TerminalsConfig::leadSec(),
                    null,
                    TerminalsConfig::bookingSlotOpts()
                );
                $operators = self::filterOperators(
                    is_array($avail['operators'] ?? null) ? $avail['operators'] : [],
                    $allowExecutors,
                    $busy
                );
                foreach ($operators as $op) {
                    if ((int)($op['id'] ?? 0) === $operatorId) {
                        $rawSlots = array_map('intval', $op['slots'] ?? []);
                        $slots = class_exists(BookingSlots::class, false)
                            ? BookingSlots::filterListSlots($rawSlots, TerminalsConfig::slotIntervalSec())
                            : $rawSlots;
                        $slotTimeText = (float)($op['time_text'] ?? 0);
                        $slotChillText = (float)($op['time_chill_text'] ?? 0);
                        break;
                    }
                }
            } catch (Throwable $e) {
                $slots = [];
                $operators = [];
            }
        }

        $matrix = ['columns' => [], 'rows' => [], 'cart' => ['items' => [], 'sum' => 0.0]];
        if ($categoryId > 0 && $gids === [] && !empty($q['rename'])) {
            $mode = 'rename';
        } elseif ($pickSlot && $gids !== [] && $operatorId > 0) {
            $mode = 'pick_slot';
        } elseif ($categoryId > 0) {
            // Stay on matrix for cart / specialist pick — no separate pick_operator screen.
            $mode = 'category';
            $matrix = TerminalFloorCatalog::buildMatrix(
                $categoryProducts,
                $categoryId,
                $operatorId,
                (int)$terminal['id'],
                $balance,
                (float)$discountCtx['base_pct'],
                $allowExecutors,
                $busyMap,
                $gids,
                $discountCtx
            );
        } else {
            $mode = 'home';
        }

        $cart = is_array($matrix['cart'] ?? null) ? $matrix['cart'] : ['items' => [], 'sum' => 0.0];

        return self::withHomeExtras([
            'ok' => true,
            'mode' => $mode,
            'terminal' => $terminal,
            'guest' => $guest,
            'client_id' => $clientId,
            'admin_id' => $claimedAdminId,
            'operator_id' => $operatorId,
            'catalog' => $catalog,
            'catalog_groups' => $catalogGroups,
            'category_id' => $categoryId,
            'category_name' => $categoryName,
            'category_products' => $categoryProducts,
            'matrix' => $matrix,
            'cart' => $cart,
            'flyer' => [
                'enabled' => $flyerEnabledSys,
                'on' => $flyerPct > 0,
                'pct' => $flyerPct > 0 ? $flyerPct : $flyerDiscountCfg,
            ],
            'promo' => [
                'active' => $promoActive,
                'positions_configured' => !empty($discount['promo_positions']),
            ],
            'bracelet_num' => (string)$numState['num'],
            'gids' => $gids,
            'operators' => $operators,
            'slots' => $slots,
            'slot_time_text' => $slotTimeText,
            'slot_chill_text' => $slotChillText,
            'busy_executors' => $busy,
            'message' => $message,
            'error' => $error,
            'query' => $q,
            'onec_live' => TerminalsConfig::onecLive() && empty($q['disable1C']),
            'onec_stub' => $disable1c,
        ], $terminal, $guest, $clientId, $bracelet, $disable1c, $cacheTtl);
    }

    /**
     * Soft-dep likespa: scan register + client/flyer/promo discount context for /t.
     *
     * @param array<string,mixed> $terminal
     * @param array<string,mixed> $guest
     * @param list<int> $gids
     * @return array{
     *   base_pct:float,flyer_pct:float,flyer_enabled:bool,flyer_cfg:float,
     *   promo_active:bool,promo_positions:bool,type_limits:array<int,int|string>
     * }
     */
    private static function resolveDiscountContext(
        array $terminal,
        array $guest,
        int $clientId,
        string $bracelet,
        int $claimedAdminId,
        bool $flyerOn,
        int $categoryId,
        array $gids
    ): array {
        $out = [
            'base_pct' => 0.0,
            'flyer_pct' => 0.0,
            'flyer_enabled' => false,
            'flyer_cfg' => 0.0,
            'promo_active' => false,
            'promo_positions' => false,
            'type_limits' => [],
        ];

        $id1c = trim((string)($guest['client_id'] ?? ''));
        $phone = trim((string)($guest['phone'] ?? $guest['data']['phone'] ?? ''));
        if ($phone === '' && $clientId > 0 && class_exists(ClientsRepository::class, false)) {
            $cl = ClientsRepository::find($clientId);
            if ($cl !== null) {
                $phone = trim((string)($cl['tel'] ?? ''));
            }
        }

        // Term-scan INSERT is early (maybeRegisterTermScanEarly); here only discount context.

        $clientPct = 0.0;
        if ($clientId > 0
            && class_exists(ClientsRepository::class, false)
            && class_exists(ClientStatusesRepository::class, false)
        ) {
            $cl = ClientsRepository::find($clientId);
            $statusId = $cl !== null ? (int)($cl['status_id'] ?? 0) : 0;
            if ($statusId > 0) {
                $st = ClientStatusesRepository::find($statusId);
                if ($st !== null) {
                    $clientPct = max(0.0, min(100.0, (float)($st['discount'] ?? 0)));
                }
            }
        }

        $typeLimits = [];
        $flyerCfg = 0.0;
        $flyerEnabled = false;
        $flyerPct = 0.0;
        if (class_exists(SystemFlyerPromo::class, false)) {
            try {
                $flyer = SystemFlyerPromo::load();
                $out['promo_positions'] = ($flyer['promo_position_ids'] ?? []) !== [];
                $typeLimits = is_array($flyer['discount_types'] ?? null) ? $flyer['discount_types'] : [];
                $flyerCfg = (float)($flyer['flyer_discount'] ?? 0);
                $flyerEnabled = !empty($terminal['is_terminal_flyer_discount'])
                    && !empty($flyer['flyer_enabled'])
                    && $flyerCfg > 0;
                if ($flyerEnabled && $flyerOn) {
                    $flyerPct = $flyerCfg;
                    if (class_exists(TerminalDiscount::class, false) && $typeLimits !== []) {
                        $typeIds = [];
                        if ($categoryId > 0 && class_exists(ProductsRepository::class, false)) {
                            // Cap flyer by limits of types present in cart or skip (catalog caps later).
                        }
                        foreach ($gids as $gid) {
                            if (!class_exists(ProductsRepository::class, false)) {
                                break;
                            }
                            $p = ProductsRepository::find((int)$gid);
                            if ($p !== null) {
                                $typeIds[] = (int)($p['type_id'] ?? $p['type'] ?? 0);
                            }
                        }
                        if ($typeIds !== []) {
                            $flyerPct = TerminalDiscount::capFlyerByTypes($flyerPct, $typeIds, $typeLimits);
                        }
                    }
                }
            } catch (Throwable $e) {
                $flyerEnabled = false;
            }
        }

        $promoActive = false;
        if (class_exists(TermScansRepository::class, false)
            && TermScansRepository::isDiscountMechanismEnabled()
        ) {
            try {
                $promoActive = TermScansRepository::hasPromoDiscount(
                    TermScansRepository::dayStart(time()),
                    $id1c,
                    $clientId,
                    $phone
                );
            } catch (Throwable $e) {
                $promoActive = false;
            }
        }

        // Oper: flyerOn replaces client % as the non-promo base.
        $basePct = $flyerPct > 0 ? $flyerPct : $clientPct;

        $out['base_pct'] = $basePct;
        $out['flyer_pct'] = $flyerPct;
        $out['flyer_enabled'] = $flyerEnabled;
        $out['flyer_cfg'] = $flyerCfg;
        $out['promo_active'] = $promoActive;
        $out['type_limits'] = $typeLimits;
        return $out;
    }

    /**
     * Cancel flow: reason always; staff login if procedure already started.
     *
     * @param array<string,mixed> $q
     * @return array{ok?:bool,mode?:string,error?:string,cancel?:array<string,mixed>,results?:list<array<string,mixed>>}
     */
    private static function handleCancelFlow(
        array $q,
        int $clientId,
        bool $disable1c,
        string $token
    ): array {
        $procId = (int)($q['procedure_id'] ?? 0);
        if ($procId < 1) {
            $procId = (int)($q['oldProcedureOff'] ?? 0);
        }
        if ($procId < 1 || !class_exists(ProceduresRepository::class, false)) {
            return ['ok' => false, 'error' => t('terminals.t.err.cancel_proc')];
        }
        $row = ProceduresRepository::find($procId);
        if ($row === null || (int)($row['enabled'] ?? 0) < 1) {
            return ['ok' => false, 'error' => t('terminals.t.err.cancel_proc')];
        }
        $dtStart = (int)($row['dtstart'] ?? 0);
        $cancelFloor = (int)mktime(8, 0, 0);
        if ($dtStart < $cancelFloor) {
            return ['ok' => false, 'error' => t('terminals.t.err.cancel_proc')];
        }
        $procClient = (int)($row['id_user'] ?? 0);
        if ($procClient > 0 && $clientId !== $procClient) {
            return ['ok' => false, 'error' => t('terminals.t.err.cancel_client')];
        }
        $needLogin = $dtStart < time();
        $reason = trim((string)($q['doConfirm'] ?? ''));
        if ($reason === '') {
            return [
                'mode' => 'cancel_confirm',
                'cancel' => [
                    'procedure_id' => $procId,
                    'need_login' => $needLogin,
                    'product_name' => (string)($row['product_name'] ?? ('#' . (int)($row['id_good'] ?? 0))),
                    'dtstart' => $dtStart,
                ],
            ];
        }
        $cancellerName = '';
        if ($needLogin) {
            $login = trim((string)($q['loginUser'] ?? ''));
            if ($login === '' || !class_exists(UsersRepository::class, false)) {
                return [
                    'mode' => 'cancel_confirm',
                    'error' => t('terminals.t.err.cancel_auth'),
                    'cancel' => [
                        'procedure_id' => $procId,
                        'need_login' => true,
                        'product_name' => (string)($row['product_name'] ?? ''),
                        'dtstart' => $dtStart,
                    ],
                ];
            }
            $user = UsersRepository::findByLogin($login);
            if ($user === null) {
                return [
                    'mode' => 'cancel_confirm',
                    'error' => t('terminals.t.err.cancel_auth'),
                    'cancel' => [
                        'procedure_id' => $procId,
                        'need_login' => true,
                        'product_name' => (string)($row['product_name'] ?? ''),
                        'dtstart' => $dtStart,
                    ],
                ];
            }
            $cancellerName = trim((string)($user['name'] ?? $user['login'] ?? $login));
            $reason .= ' ' . t('terminals.t.cancel_by', ['name' => $cancellerName]);
        }
        $cancel = TerminalBooking::cancel($procId, true, $disable1c, $reason, $token);
        if (empty($cancel['ok'])) {
            return [
                'ok' => false,
                'error' => (string)($cancel['error'] ?? t('terminals.t.err.book')),
                'results' => [$cancel],
            ];
        }
        return ['ok' => true, 'results' => [$cancel]];
    }

    /**
     * Staff diagnose snapshot (non-public agent route).
     *
     * @return array<string,mixed>
     */
    public static function diagnose(?int $terminalId = null): array
    {
        $issues = [];
        $term = null;
        if ($terminalId !== null && $terminalId > 0) {
            $term = TerminalsRepository::find($terminalId, true);
            if ($term === null) {
                $issues[] = 'terminal_not_found';
            }
        }
        if (!TerminalsConfig::onecEnabled()) {
            $issues[] = 'onec_disabled';
        } elseif (!TerminalsConfig::onecReady()) {
            $issues[] = 'onec_incomplete';
        }
        $likespaOn = class_exists(SystemFlyerPromo::class, false);
        $flyerPromo = null;
        $promoPositions = false;
        $discountsTypesLoaded = false;
        $termScansReady = class_exists(TermScansRepository::class, false)
            && TermScansRepository::tableReady();
        if ($likespaOn) {
            try {
                $flyerPromo = SystemFlyerPromo::load();
                $promoPositions = ($flyerPromo['promo_position_ids'] ?? []) !== [];
                $discountsTypesLoaded = is_array($flyerPromo['discount_types'] ?? null);
            } catch (Throwable $e) {
                $flyerPromo = null;
            }
        }
        if (!$likespaOn) {
            $issues[] = 'likespa_offline';
        } elseif (!$termScansReady) {
            $issues[] = 'term_scans_table_missing';
        }

        return [
            'runtime_both' => true,
            'route_t' => '/t',
            'onec' => [
                'enabled' => TerminalsConfig::onecEnabled(),
                'ready' => TerminalsConfig::onecReady(),
                'live' => TerminalsConfig::onecLive(),
                'stub_mode' => TerminalsConfig::onecStubMode(false),
                'has_url' => TerminalsConfig::string('1c_url') !== '',
                'has_auth' => TerminalsConfig::string('1c_auth') !== '',
                'has_apikey' => TerminalsConfig::string('1c_apikey') !== '',
                'has_terminal' => TerminalsConfig::string('1c_terminal') !== '',
            ],
            'booking' => class_exists(BookingAvailability::class, false),
            'procedures' => class_exists(ProceduresRepository::class, false),
            'staff_bracelets' => StaffBraceletsRepository::tableReady(),
            'terminal' => $term,
            'claim_valid' => $term !== null ? TerminalsRepository::hasValidStaffClaim($term) : null,
            'discounts' => [
                'likespa' => $likespaOn,
                'term_scans_ready' => $termScansReady,
                'term_scans_enabled' => class_exists(LikespaConfig::class, false)
                    ? LikespaConfig::termScansEnabled()
                    : false,
                'flyer_enabled' => !empty($flyerPromo['flyer_enabled']),
                'flyer_discount' => (int)($flyerPromo['flyer_discount'] ?? 0),
                'terminal_flyer_button' => $term !== null
                    ? !empty($term['is_terminal_flyer_discount'])
                    : null,
                'promo_positions_configured' => $promoPositions,
                'promo_mechanism_enabled' => $termScansReady
                    && class_exists(TermScansRepository::class, false)
                    && TermScansRepository::isDiscountMechanismEnabled(),
                'discounts_types_loaded' => $discountsTypesLoaded,
            ],
            'issues' => $issues,
        ];
    }

    /**
     * Resolve terminalid query: device CODE (legacy) or numeric ID.
     *
     * @return array<string,mixed>|null
     */
    public static function resolveTerminal(string $terminalid): ?array
    {
        $terminalid = trim($terminalid);
        if ($terminalid === '') {
            return null;
        }
        if (ctype_digit($terminalid)) {
            $byId = TerminalsRepository::find((int)$terminalid, false);
            if ($byId !== null) {
                return $byId;
            }
        }
        return TerminalsRepository::findByCode($terminalid);
    }

    /**
     * @return array<string,mixed>
     */
    private static function parseInput(): array
    {
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $isPost = $method === 'POST';
        // Identity + navigation: GET (Android NFC) or POST. Writes: POST only.
        $src = $isPost ? array_merge($_GET, $_POST) : $_GET;

        $pick = static function (string $key, $default = '') use ($src) {
            return $src[$key] ?? $default;
        };

        $terminalid = trim((string)$pick('terminalid', ''));
        $braceletid = trim((string)$pick('braceletid', ''));
        $token = trim((string)$pick('token', ''));

        $getTimeRaw = (string)$pick('getTime', '');
        $doStart = (string)$pick('doStart', '');
        $doConfirm = trim((string)$pick('doConfirm', ''));
        $loginUser = trim((string)$pick('loginUser', ''));
        $braceletNum = trim((string)$pick('braceletNum', ''));
        $unlinkClient = !empty($pick('unlinkClient'));
        $resetCache = !empty($pick('resetCache'));
        $userNameSent = trim((string)$pick('userNameSent', ''));
        $clientPhoneSent = trim((string)$pick('clientPhoneSent', ''));

        $mutateIgnored = false;
        if (!$isPost) {
            // Keep nav: getTime=1 (slot UI), doStart=2 without reason (cancel confirm screen).
            $commitGetTime = ctype_digit($getTimeRaw) && (int)$getTimeRaw >= 1000000000;
            if ($commitGetTime) {
                $getTimeRaw = '';
                $mutateIgnored = true;
            }
            if ($doConfirm !== '' || $loginUser !== '') {
                $doConfirm = '';
                $loginUser = '';
                $mutateIgnored = true;
            }
            if ($braceletNum !== '') {
                $braceletNum = '';
                $mutateIgnored = true;
            }
            if ($unlinkClient || $resetCache) {
                $unlinkClient = false;
                $resetCache = false;
                $mutateIgnored = true;
            }
            if ($userNameSent !== '' || $clientPhoneSent !== '') {
                $userNameSent = '';
                $clientPhoneSent = '';
                $mutateIgnored = true;
            }
        }

        $common = [
            'adminId' => (int)$pick('adminId', 0),
            'gids' => (string)$pick('gids', ''),
            'getTime' => $getTimeRaw,
            'doStart' => $doStart,
            'doConfirm' => $doConfirm,
            'loginUser' => $loginUser,
            'disable1C' => !empty($pick('disable1C')),
            'procedure_id' => (int)$pick('procedure_id', 0),
            'oldProcedureOff' => (int)$pick('oldProcedureOff', 0),
            'category' => (int)$pick('category', 0),
            'braceletNum' => $braceletNum,
            'unlinkClient' => $unlinkClient,
            'resetCache' => $resetCache,
            'forceLimit' => !empty($pick('forceLimit')),
            'flyerOn' => !empty($pick('flyerOn')),
            'rename' => !empty($pick('rename')),
            'userNameSent' => $userNameSent,
            'clientPhoneSent' => $clientPhoneSent,
            'mutate_ignored' => $mutateIgnored,
        ];

        if ($terminalid === '' || $braceletid === '') {
            return array_merge($common, [
                'ok' => false,
                'error' => t('terminals.t.err.params'),
                'need_redirect' => false,
                'terminalid' => $terminalid,
                'braceletid' => $braceletid,
                'token' => $token,
            ]);
        }

        if ($token === '') {
            $salt = (string)Config::get('salts', 'site', 'freesaas');
            $token = md5($salt . microtime(true) . random_int(1, 999999));
            return array_merge($common, [
                'ok' => false,
                'error' => '',
                'need_redirect' => true,
                'redirect_token' => $token,
                'terminalid' => $terminalid,
                'braceletid' => $braceletid,
                'token' => $token,
            ]);
        }

        return array_merge($common, [
            'ok' => true,
            'error' => '',
            'need_redirect' => false,
            'terminalid' => $terminalid,
            'braceletid' => $braceletid,
            'token' => $token,
        ]);
    }

    /**
     * Resolve / create likespa client for guest (phone + name), even when 1C is stubbed.
     *
     * @param array<string,mixed> $guest
     * @return array{mode?:string,client_id:int,display_name?:string,message?:string,error?:string,phone_hint?:string}
     */
    private static function ensureGuestIdentity(
        int $clientId,
        array $guest,
        string $phoneRaw,
        string $nameRaw,
        bool $forceRename
    ): array {
        if (!class_exists(ClientsRepository::class, false) || !Database::tableExists('base_clients')) {
            return ['client_id' => $clientId];
        }
        $onecId = trim((string)($guest['client_id'] ?? ''));
        $out = ['client_id' => $clientId, 'display_name' => ''];

        if ($clientId < 1) {
            $phone = self::normalizePhone($phoneRaw);
            if ($phone === '') {
                $hint = self::normalizePhone((string)($guest['phone'] ?? $guest['data']['phone'] ?? ''));
                return [
                    'mode' => 'need_phone',
                    'client_id' => 0,
                    'phone_hint' => $hint,
                ];
            }
            $found = ClientsRepository::findByPhoneDigits($phone);
            if ($found !== null) {
                $clientId = (int)$found['id'];
            } else {
                try {
                    $clientId = ClientsRepository::create(ClientsRepository::dataFromPost([
                        'name' => $phone,
                        'tel' => $phone,
                        'mail' => $phone . '@noemail.ru',
                        'enabled' => '1',
                        'subscribe' => '1',
                    ]));
                } catch (Throwable $e) {
                    return [
                        'mode' => 'need_phone',
                        'client_id' => 0,
                        'error' => $e->getMessage(),
                        'phone_hint' => $phone,
                    ];
                }
            }
            $out['client_id'] = $clientId;
            $out['message'] = t('terminals.t.client_ready');
            if ($onecId !== '' && class_exists(ClientOnecBindingsRepository::class, false)
                && ClientOnecBindingsRepository::tableReady()) {
                try {
                    ClientOnecBindingsRepository::bind($clientId, $onecId, time() + 12 * 3600);
                } catch (Throwable $e) {
                    // keep going — local client is enough for stub floor
                }
            }
        }

        $row = ClientsRepository::find($clientId);
        if ($row === null) {
            return ['mode' => 'need_phone', 'client_id' => 0];
        }
        $name = trim((string)($row['name'] ?? ''));
        $tel = trim((string)($row['tel'] ?? ''));
        $placeholder = $name === '' || $name === $tel || self::isPlaceholderGuestName($name);
        $needsName = $forceRename || $placeholder;

        if ($needsName) {
            $nameIn = trim($nameRaw);
            if ($nameIn === '' || self::isPlaceholderGuestName($nameIn)) {
                return [
                    'mode' => 'need_name',
                    'client_id' => $clientId,
                    // Real name on ?rename=1 stays visible; stubs leave the field empty.
                    'display_name' => (!$placeholder && $name !== '') ? $name : '',
                ];
            }
            $parts = preg_split('/\s+/u', $nameIn) ?: [];
            Database::pdo()->prepare(
                'UPDATE base_clients SET name = ?, surname = ?, pname = ? WHERE id = ?'
            )->execute([
                mb_substr($nameIn, 0, 200),
                mb_substr((string)($parts[0] ?? ''), 0, 100),
                mb_substr((string)($parts[1] ?? ''), 0, 100),
                $clientId,
            ]);
            $out['display_name'] = $nameIn;
            $out['message'] = t('terminals.t.name_saved');
            $out['client_id'] = $clientId;
            return $out;
        }

        $out['display_name'] = $name;
        $out['client_id'] = $clientId;
        return $out;
    }

    /** 1C/oper stub labels that must not prefill the rename field. */
    private static function isPlaceholderGuestName(string $name): bool
    {
        $name = trim($name);
        if ($name === '') {
            return true;
        }
        return mb_stripos($name, 'Посетитель') !== false
            || mb_stripos($name, 'Stub Guest') !== false
            || mb_stripos($name, 'NO 1C') !== false;
    }

    /** Same rules as storefront cart (`SitesGuest::normalizePhone`): 7XXXXXXXXXX. */
    private static function normalizePhone(string $raw): string
    {
        if (class_exists(SitesGuest::class, false)) {
            return SitesGuest::normalizePhone($raw);
        }
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if ($digits === '') {
            return '';
        }
        if (strlen($digits) === 11 && $digits[0] === '8') {
            $digits = '7' . substr($digits, 1);
        }
        if (strlen($digits) === 10) {
            $digits = '7' . $digits;
        }
        if (strlen($digits) !== 11 || $digits[0] !== '7') {
            return '';
        }
        return $digits;
    }

    /**
     * Oper/likespa parity: INSERT base_term_scans on primary floor home before
     * NUM/phone/name. Once per scan token (session). Soft-dep likespa.
     *
     * @param array<string,mixed> $q
     * @param array<string,mixed> $terminal
     * @param array<string,mixed> $guest
     */
    private static function maybeRegisterTermScanEarly(
        array $q,
        array $terminal,
        array $guest,
        int $clientId,
        string $bracelet,
        int $claimedAdminId,
        bool $isEmployee
    ): void {
        if ($isEmployee || $claimedAdminId < 1) {
            return;
        }
        // Primary home only — not category / cart / slot / commit navigations.
        if ((int)($q['category'] ?? 0) > 0
            || trim((string)($q['gids'] ?? '')) !== ''
            || trim((string)($q['getTime'] ?? '')) !== ''
        ) {
            return;
        }
        if (!class_exists(TermScansRepository::class, false)
            || !class_exists(LikespaConfig::class, false)
            || !LikespaConfig::termScansEnabled()
        ) {
            return;
        }
        $token = trim((string)($q['token'] ?? ''));
        if ($token !== '') {
            if (!isset($_SESSION['termScanTokens']) || !is_array($_SESSION['termScanTokens'])) {
                $_SESSION['termScanTokens'] = [];
            }
            if (!empty($_SESSION['termScanTokens'][$token])) {
                return;
            }
        }
        $id1c = trim((string)($guest['client_id'] ?? ''));
        $phone = trim((string)($guest['phone'] ?? $guest['data']['phone'] ?? ''));
        if ($phone === '' && $clientId > 0 && class_exists(ClientsRepository::class, false)) {
            $cl = ClientsRepository::find($clientId);
            if ($cl !== null) {
                $phone = trim((string)($cl['tel'] ?? ''));
            }
        }
        try {
            $scanId = TermScansRepository::register([
                'id_admin' => $claimedAdminId,
                'id_terminal' => (int)($terminal['id'] ?? 0),
                'bracelet_uid' => $bracelet,
                'id_1c' => $id1c,
                'id_user' => $clientId,
                'phone' => $phone,
                'phone_1c' => $phone,
                'dt' => time(),
            ]);
        } catch (Throwable $e) {
            return;
        }
        if ($token !== '') {
            $_SESSION['termScanTokens'][$token] = 1;
            if ($scanId !== null && $scanId > 0) {
                if (!isset($_SESSION['termScanIds']) || !is_array($_SESSION['termScanIds'])) {
                    $_SESSION['termScanIds'] = [];
                }
                $_SESSION['termScanIds'][$token] = $scanId;
            }
            // Cap growth on long-lived public terminals.
            if (count($_SESSION['termScanTokens']) > 300) {
                $_SESSION['termScanTokens'] = array_slice($_SESSION['termScanTokens'], -150, null, true);
            }
            if (isset($_SESSION['termScanIds']) && count($_SESSION['termScanIds']) > 300) {
                $_SESSION['termScanIds'] = array_slice($_SESSION['termScanIds'], -150, null, true);
            }
        }
    }

    /**
     * After guest CRM identity: fill empty fields on the early term-scan row.
     *
     * @param array<string,mixed> $q
     * @param array<string,mixed> $guest
     */
    private static function maybeSupplementTermScan(
        array $q,
        array $guest,
        int $clientId,
        string $bracelet,
        int $claimedAdminId
    ): void {
        if ($clientId < 1 && trim((string)($guest['client_id'] ?? '')) === '') {
            return;
        }
        if (!class_exists(TermScansRepository::class, false)
            || !class_exists(LikespaConfig::class, false)
            || !LikespaConfig::termScansEnabled()
        ) {
            return;
        }
        $token = trim((string)($q['token'] ?? ''));
        if ($token === '' || empty($_SESSION['termScanTokens'][$token])) {
            return;
        }
        $phone = trim((string)($guest['phone'] ?? $guest['data']['phone'] ?? ''));
        if ($phone === '' && $clientId > 0 && class_exists(ClientsRepository::class, false)) {
            $cl = ClientsRepository::find($clientId);
            if ($cl !== null) {
                $phone = trim((string)($cl['tel'] ?? ''));
            }
        }
        try {
            TermScansRepository::supplementClientData([
                'token' => $token,
                'id_1c' => trim((string)($guest['client_id'] ?? '')),
                'id_user' => $clientId,
                'phone' => $phone,
                'phone_1c' => $phone,
                'bracelet_uid' => $bracelet,
                'id_admin' => $claimedAdminId,
                'dt' => time(),
            ]);
        } catch (Throwable $e) {
            // Soft.
        }
    }

    /**
     * Once per scan token+client: guest identity resolved on /t.
     * Emits terminals.guest.identified and (soft) orders.client.arrived —
     * orders owns arrive transition; terminals must not call OrdersRepository.
     *
     * @param array<string,mixed> $terminal
     */
    private static function emitGuestIdentified(
        int $clientId,
        array $terminal,
        string $braceletUid,
        string $braceletNum,
        int $adminId,
        string $token
    ): void {
        if ($clientId < 1) {
            return;
        }
        $token = trim($token);
        $dedupeKey = ($token !== '' ? $token : $braceletUid) . ':' . $clientId;
        if (!isset($_SESSION['t_guest_identified']) || !is_array($_SESSION['t_guest_identified'])) {
            $_SESSION['t_guest_identified'] = [];
        }
        if (!empty($_SESSION['t_guest_identified'][$dedupeKey])) {
            return;
        }
        $_SESSION['t_guest_identified'][$dedupeKey] = time();
        // Cap session map growth on long-lived public terminals.
        if (count($_SESSION['t_guest_identified']) > 200) {
            $_SESSION['t_guest_identified'] = array_slice(
                $_SESSION['t_guest_identified'],
                -100,
                null,
                true
            );
        }
        $payload = [
            'client_id' => $clientId,
            'terminal_id' => (int)($terminal['id'] ?? 0),
            'bracelet_uid' => $braceletUid,
            'bracelet_num' => $braceletNum,
            'admin_id' => $adminId,
            'token' => $token,
        ];
        EventBus::emit('terminals.guest.identified', $payload);
        // orders contract (no hard dep): only when orders module is enabled/loaded.
        if (ModuleManager::isEnabled('orders')) {
            EventBus::emit('orders.client.arrived', [
                'client_id' => $clientId,
                'reason' => 'terminal_floor',
                'meta' => [
                    'terminal_id' => (int)($terminal['id'] ?? 0),
                    'bracelet_uid' => $braceletUid,
                    'bracelet_num' => $braceletNum,
                    'admin_id' => $adminId,
                    'token' => $token,
                ],
            ]);
        }
    }

    /**
     * @param array<string,mixed> $state
     * @param array<string,mixed> $terminal
     * @param array<string,mixed> $guest
     * @return array<string,mixed>
     */
    private static function withHomeExtras(
        array $state,
        array $terminal,
        array $guest,
        int $clientId,
        string $bracelet,
        bool $disable1c,
        int $cacheTtl
    ): array {
        $claimedAdminId = (int)($terminal['id_admin'] ?? $state['admin_id'] ?? 0);
        $restricted = !empty($terminal['masseur_mode']) || !empty($terminal['is_term_admin_mode']);
        $state['ticket'] = TerminalFloorHome::ticketClock($guest);
        $state['diag'] = TerminalFloorHome::diag(
            $terminal,
            $bracelet,
            $guest,
            $claimedAdminId,
            $disable1c,
            $cacheTtl
        );
        $state['history'] = (!$restricted && !empty($terminal['is_terminal_client_history']) && $clientId > 1)
            ? TerminalFloorHome::clientHistory($clientId, 10)
            : [];
        $state['day_load'] = (!$restricted && !empty($terminal['is_terminal_show_day']))
            ? TerminalFloorHome::dayLoad()
            : ['operators' => [], 'blocks' => [], 'hour_marks' => [], 'day_start' => 0, 'day_end' => 0];
        $state['show_unlink'] = $clientId > 0;
        $state['bracelet_num'] = (string)($state['bracelet_num'] ?? '');
        return $state;
    }

    /**
     * @return array<string,mixed>
     */
    private static function guestCached(string $token, string $bracelet, bool $disable1c, int &$cacheTtl): array
    {
        $cacheTtl = 0;
        if ($token !== '' && isset($_SESSION['t_guest_cache'][$token]) && is_array($_SESSION['t_guest_cache'][$token])) {
            $hit = $_SESSION['t_guest_cache'][$token];
            $valid = (int)($hit['valid'] ?? 0);
            if ($valid > time() && (string)($hit['bracelet'] ?? '') === $bracelet && is_array($hit['guest'] ?? null)) {
                $cacheTtl = $valid - time();
                return $hit['guest'];
            }
        }
        $guest = OnecClient::getBraceletInfo($bracelet, $disable1c);
        if (!empty($guest['ok']) && $token !== '') {
            if (!isset($_SESSION['t_guest_cache']) || !is_array($_SESSION['t_guest_cache'])) {
                $_SESSION['t_guest_cache'] = [];
            }
            $_SESSION['t_guest_cache'][$token] = [
                'valid' => time() + 120,
                'bracelet' => $bracelet,
                'guest' => $guest,
            ];
            $cacheTtl = 120;
        }
        return $guest;
    }

    /**
     * @param array<string,mixed> $q
     * @return array<string,mixed>
     */
    private static function err(array $q, string $msg): array
    {
        return [
            'ok' => false,
            'error' => $msg,
            'query' => $q,
            'mode' => 'error',
        ];
    }

    /** @return list<int> */
    private static function parseGids(string $raw): array
    {
        $raw = str_replace([',', ';', ' '], '#', $raw);
        $out = [];
        foreach (explode('#', $raw) as $p) {
            $n = (int)$p;
            if ($n > 0) {
                $out[$n] = $n;
            }
        }
        return array_values($out);
    }

    /**
     * Full terminal catalog (legacy): all bookable services, optional GOODS allow-list.
     * Claimed admin is not the performer — do not filter by operator rates here.
     *
     * @param array<string,mixed> $terminal
     * @return list<array<string,mixed>>
     */
    private static function buildCatalog(array $terminal): array
    {
        if (!class_exists(BookingCatalog::class, false)) {
            return [];
        }
        $services = BookingCatalog::listServices();
        $allowGoods = self::parseGids((string)($terminal['goods'] ?? ''));
        if ($allowGoods !== []) {
            $allow = array_fill_keys($allowGoods, true);
            $services = array_values(array_filter(
                $services,
                static fn (array $p): bool => isset($allow[(int)($p['id'] ?? 0)])
            ));
        }
        return $services;
    }

    /**
     * @param list<array<string,mixed>> $operators
     * @param list<int> $allowExecutors
     * @param list<int> $busy
     * @return list<array<string,mixed>>
     */
    private static function filterOperators(array $operators, array $allowExecutors, array $busy): array
    {
        $allow = $allowExecutors !== [] ? array_fill_keys($allowExecutors, true) : null;
        $busySet = array_fill_keys($busy, true);
        $out = [];
        foreach ($operators as $op) {
            $id = (int)($op['id'] ?? 0);
            if ($id < 1) {
                continue;
            }
            if ($allow !== null && !isset($allow[$id])) {
                continue;
            }
            $op['busy'] = isset($busySet[$id]);
            $out[] = $op;
        }
        return $out;
    }
}
