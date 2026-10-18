<?php
declare(strict_types=1);

/**
 * Booking tools provider for the client MCP (services, specialists, slots, book, own bookings, cancel).
 * Registered through EventBus client_mcp.tools like any other module; served only when its modules are on.
 */
final class ClientMcpBookingTools
{
    public const DAYS_AHEAD = 14;
    private const MAX_PRODUCTS = 10;

    /** @return bool booking engine usable on the storefront */
    public static function bookingReady(): bool
    {
        return class_exists(SitesBooking::class, false) && SitesBooking::active();
    }

    /**
     * EventBus client_mcp.tools subscriber.
     *
     * @param mixed $payload
     * @return mixed
     */
    public static function onTools($payload)
    {
        if (!is_array($payload)) {
            return $payload;
        }
        $productIds = [
            'type' => 'array',
            'items' => ['type' => 'integer'],
            'minItems' => 1,
            'maxItems' => self::MAX_PRODUCTS,
            'description' => 'Service ids from list_services (one booking may combine several services).',
        ];
        $token = ['type' => 'string', 'description' => 'client_token returned by identify_client.'];
        $read = ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false];
        $write = ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false];
        $ready = [self::class, 'bookingReady'];
        $engine = ['booking', 'catalog'];

        $payload['tools'][] = [
            'name' => 'list_services',
            'title' => 'List bookable services',
            'description' => 'Bookable services with id, category, online price, procedure_min (time with the specialist) '
                . 'and duration_min (slot incl. rest). Optional text filter q.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'q' => ['type' => 'string', 'description' => 'Optional case-insensitive substring of service or category name.'],
                ],
                'additionalProperties' => false,
            ],
            'annotations' => $read,
            'handler' => [self::class, 'listServices'],
            'module' => 'client_mcp',
            'requires' => $engine,
            'ready' => $ready,
            'order' => 10,
            'instructions' => 'Booking flow: list_services -> get_availability -> confirm service, date, time and specialist '
                . 'with the user -> identify_client -> create_booking.',
        ];
        $payload['tools'][] = [
            'name' => 'list_specialists',
            'title' => 'Specialists for services',
            'description' => 'Specialists (first name + photo) who can perform the given services within the booking horizon, '
                . 'with the first date they have free time.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => ['product_ids' => $productIds],
                'required' => ['product_ids'],
                'additionalProperties' => false,
            ],
            'annotations' => $read,
            'handler' => [self::class, 'listSpecialists'],
            'module' => 'client_mcp',
            'requires' => $engine,
            'ready' => $ready,
            'order' => 11,
        ];
        $payload['tools'][] = [
            'name' => 'get_availability',
            'title' => 'Free time slots',
            'description' => 'Without date: dates with free slots in the booking horizon. With date (YYYY-MM-DD): free local start '
                . 'times (HH:MM) per specialist plus any_specialist_slots (union). operator_id narrows to one specialist (0 = any).',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'product_ids' => $productIds,
                    'date' => ['type' => 'string', 'description' => 'Local date YYYY-MM-DD. Omit to list dates.'],
                    'operator_id' => ['type' => 'integer', 'description' => 'Specialist id from list_specialists; 0 or omit = any.'],
                ],
                'required' => ['product_ids'],
                'additionalProperties' => false,
            ],
            'annotations' => $read,
            'handler' => [self::class, 'getAvailability'],
            'module' => 'client_mcp',
            'requires' => $engine,
            'ready' => $ready,
            'order' => 12,
        ];
        $payload['tools'][] = [
            'name' => 'create_booking',
            'title' => 'Book the appointment',
            'description' => 'Create the booking after the user confirmed service, date, time and specialist. '
                . 'Pass date + time exactly as returned by get_availability (or dt_start in unix seconds). '
                . 'Returns order id, summary and payment block (show payment data to the user if present).',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'client_token' => $token,
                    'product_ids' => $productIds,
                    'date' => ['type' => 'string', 'description' => 'YYYY-MM-DD.'],
                    'time' => ['type' => 'string', 'description' => 'HH:MM local, one of the slots from get_availability.'],
                    'dt_start' => ['type' => 'integer', 'description' => 'Alternative to date + time: unix seconds.'],
                    'operator_id' => ['type' => 'integer', 'description' => 'Specialist id; 0 or omit = any free specialist.'],
                    'client_name' => ['type' => 'string', 'description' => 'Client name if still required.'],
                    'email' => ['type' => 'string', 'description' => 'Optional email.'],
                    'comment' => ['type' => 'string', 'description' => 'Optional wishes (max 500 chars).'],
                    'consent' => ['type' => 'boolean', 'description' => 'True if the user accepted consent_url (when required).'],
                ],
                'required' => ['client_token', 'product_ids'],
                'additionalProperties' => false,
            ],
            'annotations' => $write,
            'handler' => [self::class, 'createBooking'],
            'module' => 'client_mcp',
            'requires' => ['booking', 'catalog', 'orders'],
            'ready' => $ready,
            'order' => 60,
        ];
        $payload['tools'][] = [
            'name' => 'my_bookings',
            'title' => 'My bookings',
            'description' => 'Bookings created in this client session (all future bookings when the session is verified), '
                . 'with state and payment block.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => ['client_token' => $token],
                'required' => ['client_token'],
                'additionalProperties' => false,
            ],
            'annotations' => $read,
            'handler' => [self::class, 'myBookings'],
            'module' => 'client_mcp',
            'requires' => ['orders'],
            'order' => 70,
        ];
        $payload['tools'][] = [
            'name' => 'cancel_booking',
            'title' => 'Cancel a booking',
            'description' => 'Cancel a booking from my_bookings. Confirm with the user first.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'client_token' => $token,
                    'order_id' => ['type' => 'integer'],
                    'reason' => ['type' => 'string', 'description' => 'Optional reason.'],
                ],
                'required' => ['client_token', 'order_id'],
                'additionalProperties' => false,
            ],
            'annotations' => ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true, 'openWorldHint' => false],
            'handler' => [self::class, 'cancelBooking'],
            'module' => 'client_mcp',
            'requires' => ['orders'],
            'order' => 71,
        ];
        return $payload;
    }

    /**
     * EventBus client_mcp.business_info subscriber: booking rules + registration fields.
     *
     * @param mixed $payload
     * @return mixed
     */
    public static function onBusinessInfo($payload)
    {
        if (!is_array($payload) || !is_array($payload['info'] ?? null)
            || !in_array('create_booking', (array)($payload['info']['tools'] ?? []), true)
        ) {
            return $payload;
        }
        [$hs, $ms] = BookingAvailability::workDayStart();
        [$he, $me] = BookingAvailability::workDayEnd();
        $info = $payload['info'];
        $info['working_hours'] = [
            'start' => sprintf('%02d:%02d', $hs, $ms),
            'end' => sprintf('%02d:%02d', $he, $me),
        ];
        $info['booking'] = [
            'min_lead_minutes' => (int)ceil(SitesBooking::leadSec() / 60),
            'days_ahead' => self::DAYS_AHEAD,
            'any_specialist' => true,
        ];
        foreach ([
            ['name' => 'product_ids', 'tool' => 'create_booking', 'required' => true],
            ['name' => 'date', 'tool' => 'create_booking', 'required' => true, 'note' => 'YYYY-MM-DD (or dt_start).'],
            ['name' => 'time', 'tool' => 'create_booking', 'required' => true, 'note' => 'HH:MM from get_availability.'],
            ['name' => 'operator_id', 'tool' => 'create_booking', 'required' => false, 'note' => '0 = any specialist.'],
            ['name' => 'email', 'tool' => 'create_booking', 'required' => false],
            ['name' => 'comment', 'tool' => 'create_booking', 'required' => false],
        ] as $field) {
            $info['registration']['fields'][] = $field;
        }
        $payload['info'] = $info;
        return $payload;
    }

    // ---- read tools -------------------------------------------------------

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    public static function listServices(array $args): array
    {
        $q = mb_strtolower(trim((string)($args['q'] ?? '')));
        $items = [];
        foreach (self::services() as $s) {
            if ($q !== '' && mb_strpos(mb_strtolower($s['name'] . ' ' . $s['category']), $q) === false) {
                continue;
            }
            $items[] = $s;
        }
        return [
            'ok' => true,
            'currency' => ClientMcpConfig::currency(),
            'count' => count($items),
            'services' => $items,
        ];
    }

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    public static function listSpecialists(array $args): array
    {
        $productIds = self::productIds($args);
        $out = [];
        foreach (SitesBooking::listMastersAhead($productIds, self::DAYS_AHEAD) as $card) {
            $out[] = [
                'operator_id' => (int)$card['id'],
                'name' => (string)$card['display_name'],
                'photo' => ClientMcpContext::absoluteUrl((string)($card['photo'] ?? '')),
                'first_free_date' => (string)($card['first_date'] ?? ''),
            ];
        }
        return [
            'ok' => true,
            'product_ids' => $productIds,
            'specialists' => $out,
            'hint' => $out === [] ? t('client_mcp.hint.no_specialists') : '',
        ];
    }

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    public static function getAvailability(array $args): array
    {
        $productIds = self::productIds($args);
        $operatorId = max(0, (int)($args['operator_id'] ?? 0));
        $date = trim((string)($args['date'] ?? ''));

        if ($date === '') {
            $dates = [];
            foreach (SitesBooking::listDatesForOperator($productIds, $operatorId, self::DAYS_AHEAD) as $row) {
                $ts = BookingAvailability::parseDayStart((string)$row['date']) ?? 0;
                $dates[] = [
                    'date' => (string)$row['date'],
                    'weekday' => $ts > 0 ? date('l', $ts) : '',
                    'free_slots' => (int)$row['slots'],
                ];
            }
            return [
                'ok' => true,
                'mode' => 'dates',
                'product_ids' => $productIds,
                'operator_id' => $operatorId,
                'dates' => $dates,
                'next' => 'Call get_availability again with date to get start times.',
            ];
        }

        $dayStart = self::assertBookableDate($date);
        $data = BookingAvailability::forDay(
            date('Y-m-d', $dayStart),
            $productIds,
            $operatorId > 0 ? $operatorId : null,
            null,
            false,
            SitesBooking::leadSec(),
            null,
            class_exists(SitesBooking::class, false) ? SitesBooking::bookingSlotOpts() : []
        );

        $specialists = [];
        $union = [];
        foreach (SitesBooking::publicOperatorsFromDay($data) as $op) {
            $slots = [];
            foreach ($op['slots'] as $ts) {
                $ts = (int)$ts;
                $slots[] = date('H:i', $ts);
                $union[$ts] = true;
            }
            $specialists[] = [
                'operator_id' => (int)$op['id'],
                'name' => (string)$op['display_name'],
                'photo' => ClientMcpContext::absoluteUrl((string)($op['photo'] ?? '')),
                'slots' => $slots,
            ];
        }
        ksort($union, SORT_NUMERIC);
        $any = [];
        foreach (array_keys($union) as $ts) {
            $any[] = date('H:i', (int)$ts);
        }

        return [
            'ok' => true,
            'mode' => 'day',
            'date' => (string)($data['date'] ?? $date),
            'weekday' => date('l', $dayStart),
            'product_ids' => $productIds,
            'duration_min' => (int)round(((int)($data['total_time'] ?? 0)) / 60),
            'specialists' => $specialists,
            'any_specialist_slots' => $any,
            'empty_reason' => $specialists === [] ? (string)($data['empty_reason'] ?? 'no_slots') : '',
        ];
    }

    // ---- write tools ------------------------------------------------------

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    public static function createBooking(array $args): array
    {
        $host = ClientMcpContext::host();
        $session = ClientMcpContext::session($args);

        $name = ClientMcpContext::cleanName((string)($args['client_name'] ?? ''));
        if ($name === '') {
            $name = (string)$session['client_name'];
        }
        $consentNow = !empty($args['consent']);
        $missing = ClientMcpCoreTools::missingFields([
            'known' => $session['known'],
            'client_name' => $name,
            'consent_at' => $consentNow ? time() : (int)$session['consent_at'],
            'verify_mode' => $session['verify_mode'],
            'verified' => $session['verified'],
        ]);
        if ($missing !== []) {
            throw new ClientMcpToolError('missing_fields', t('client_mcp.err.missing_fields'), [
                'required_fields' => $missing,
                'consent_url' => ClientMcpHost::consentUrl($host),
            ]);
        }

        $phone = (string)$session['phone'];
        if (ClientMcpSessionsRepository::bookingsByPhoneSince($phone, time() - 86400) >= ClientMcpConfig::bookingsPerPhoneDay()) {
            throw new ClientMcpToolError('rate_limited', t('client_mcp.err.bookings_limit'));
        }

        $productIds = self::productIds($args);
        $dtStart = self::resolveDtStart($args);
        self::assertBookableDate(date('Y-m-d', $dtStart));

        $operatorId = max(0, (int)($args['operator_id'] ?? 0));
        if ($operatorId < 1) {
            $operatorId = SitesBooking::resolveOperatorForSlot(date('Y-m-d', $dtStart), $productIds, $dtStart);
            if ($operatorId < 1) {
                throw new ClientMcpToolError('slot_unavailable', t('client_mcp.err.slot'));
            }
        }

        $email = trim((string)($args['email'] ?? ''));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new ClientMcpToolError('invalid_email', t('client_mcp.err.email'));
        }
        $userComment = mb_substr(trim((string)($args['comment'] ?? '')), 0, 500);
        $comment = t('client_mcp.order.comment_tag', ['host' => $host['host']])
            . ($userComment !== '' ? ': ' . $userComment : '');

        $index = self::serviceIndex();
        $lines = [];
        foreach ($productIds as $pid) {
            $s = $index[$pid];
            $lines[] = [
                'id' => $pid,
                'name' => $s['name'],
                'price' => (float)$s['price'],
                'price_original' => (float)$s['price_original'],
            ];
        }

        try {
            $booked = BookingBook::createOrder([
                'phone' => $phone,
                'client_id' => (int)$session['client_id'],
                'client_name' => $name,
                'mail' => $email,
                'comment' => $comment,
                'product_ids' => $productIds,
                'cart_lines' => $lines,
                'operator_id' => $operatorId,
                'dt_start' => $dtStart,
                'source' => 'mcp',
                'lead_sec' => SitesBooking::leadSec(),
                'interval_sec' => SitesBooking::slotIntervalSec(),
            ]);
        } catch (InvalidArgumentException | RuntimeException $e) {
            throw new ClientMcpToolError('booking_failed', $e->getMessage(), [
                'hint' => t('client_mcp.hint.rebook'),
            ]);
        }
        $orderId = (int)$booked['order_id'];

        ClientMcpContext::rememberOrder($session, $orderId, (int)$booked['client_id']);
        $update = ['consent' => $consentNow];
        if ($name !== '' && $name !== $session['client_name']) {
            $update['client_name'] = $name;
        }
        ClientMcpSessionsRepository::update((int)$session['id'], $update);

        EventBus::emit('client_mcp.booking.created', [
            'order_id' => $orderId,
            'site_host_id' => $host['id'],
            'host' => $host['host'],
            'session_id' => (int)$session['id'],
            'client_id' => (int)$booked['client_id'],
            'phone' => $phone,
            'lines' => $lines,
            'operator_id' => $operatorId,
            'dt_start' => $dtStart,
        ]);

        $order = OrdersRepository::find($orderId);
        return [
            'ok' => true,
            'booking' => $order !== null ? self::bookingView($order) : [
                'order_id' => $orderId,
                'start' => date('c', $dtStart),
            ],
            'message' => t('client_mcp.booked', [
                'id' => $orderId,
                'when' => date('d.m.Y H:i', $dtStart),
            ]),
        ];
    }

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    public static function myBookings(array $args): array
    {
        $session = ClientMcpContext::session($args);
        $verified = ClientMcpContext::isVerified($session);
        $ids = $session['order_ids'];
        if ($verified) {
            foreach (OrdersRepository::listFutureByClient((int)$session['client_id'], 50) as $row) {
                $ids[] = (int)$row['id'];
            }
        }
        $orders = [];
        foreach (array_values(array_unique($ids)) as $oid) {
            $full = OrdersRepository::find((int)$oid);
            if ($full !== null && ClientMcpContext::ownsOrder($session, $full)) {
                $orders[] = self::bookingView($full);
            }
        }
        return [
            'ok' => true,
            'scope' => $verified ? 'client' : 'session',
            'bookings' => $orders,
        ];
    }

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    public static function cancelBooking(array $args): array
    {
        $session = ClientMcpContext::session($args);
        $orderId = (int)($args['order_id'] ?? 0);
        $order = $orderId > 0 ? OrdersRepository::find($orderId) : null;
        if ($order === null || !ClientMcpContext::ownsOrder($session, $order)) {
            throw new ClientMcpToolError('not_found', t('client_mcp.err.order_not_found'));
        }
        $state = OrdersRepository::clientState($order);
        if ($state['state'] === 'cancelled') {
            return ['ok' => true, 'status' => 'already_cancelled', 'booking' => self::bookingView($order)];
        }
        $reason = mb_substr(trim((string)($args['reason'] ?? '')), 0, 300);
        $res = $state['cancellable']
            ? OrdersRepository::cancelForClient($orderId, (int)($order['id_user'] ?? 0), $reason)
            : ['ok' => false];
        if (empty($res['ok'])) {
            throw new ClientMcpToolError('not_cancellable', t('client_mcp.err.not_cancellable'), [
                'phone' => ClientMcpContext::businessPhone(),
            ]);
        }
        $fresh = OrdersRepository::find($orderId) ?? $order;
        return ['ok' => true, 'status' => 'cancelled', 'booking' => self::bookingView($fresh)];
    }

    // ---- helpers ----------------------------------------------------------

    /**
     * Bookable services visible on the current storefront host (catalog roots), storefront price.
     *
     * @return list<array{id:int,name:string,category_id:int,category:string,duration_min:int,procedure_min:int,price:float,price_original:float}>
     */
    private static function services(): array
    {
        $catNames = CategoriesRepository::names();
        $out = [];
        foreach (BookingCatalog::listServices() as $p) {
            if (!SitesCatalog::isProductVisible($p)) {
                continue;
            }
            $price = SitesCatalog::onlinePrice($p);
            $sec = (int)($p['time_sec'] ?? 0);
            $net = (int)($p['time_net'] ?? 0);
            $cid = (int)($p['category'] ?? 0);
            $out[] = [
                'id' => (int)$p['id'],
                'name' => (string)$p['name'],
                'category_id' => $cid,
                'category' => (string)($catNames[$cid] ?? ($p['category_name'] ?? '')),
                'duration_min' => $sec > 0 ? (int)round($sec / 60) : 0,
                'procedure_min' => $net > 0 ? (int)round($net / 60) : ($sec > 0 ? (int)round($sec / 60) : 0),
                'price' => round($price['price'], 2),
                'price_original' => round($price['original'], 2),
            ];
        }
        return $out;
    }

    /** @return array<int,array<string,mixed>> */
    private static function serviceIndex(): array
    {
        $idx = [];
        foreach (self::services() as $s) {
            $idx[(int)$s['id']] = $s;
        }
        return $idx;
    }

    /**
     * @param array<string,mixed> $args
     * @return list<int>
     */
    private static function productIds(array $args): array
    {
        $raw = $args['product_ids'] ?? [];
        if (is_string($raw) || is_int($raw)) {
            $raw = preg_split('/[,\s]+/', (string)$raw) ?: [];
        }
        $ids = [];
        foreach (is_array($raw) ? $raw : [] as $v) {
            $id = (int)$v;
            if ($id > 0 && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }
        if ($ids === [] || count($ids) > self::MAX_PRODUCTS) {
            throw new ClientMcpToolError('invalid_services', t('client_mcp.err.services'));
        }
        $index = self::serviceIndex();
        $unknown = array_values(array_filter($ids, static function (int $id) use ($index): bool {
            return !isset($index[$id]);
        }));
        if ($unknown !== []) {
            throw new ClientMcpToolError('invalid_services', t('client_mcp.err.services'), ['unknown_ids' => $unknown]);
        }
        return $ids;
    }

    /** @return int day start (local midnight) */
    private static function assertBookableDate(string $ymd): int
    {
        $dayStart = BookingAvailability::parseDayStart($ymd);
        if ($dayStart === null) {
            throw new ClientMcpToolError('invalid_date', t('client_mcp.err.date'));
        }
        $today = (int)mktime(0, 0, 0);
        $last = (int)mktime(0, 0, 0, (int)date('n'), (int)date('j') + self::DAYS_AHEAD - 1, (int)date('Y'));
        if ($dayStart < $today || $dayStart > $last) {
            throw new ClientMcpToolError('date_out_of_range', t('client_mcp.err.date_range', ['days' => self::DAYS_AHEAD]));
        }
        return $dayStart;
    }

    /** @param array<string,mixed> $args */
    private static function resolveDtStart(array $args): int
    {
        $dt = (int)($args['dt_start'] ?? 0);
        if ($dt < 1) {
            $dayStart = BookingAvailability::parseDayStart(trim((string)($args['date'] ?? '')));
            if ($dayStart !== null && preg_match('/^(\d{1,2}):(\d{2})$/', trim((string)($args['time'] ?? '')), $m)) {
                $dt = (int)mktime((int)$m[1], (int)$m[2], 0, (int)date('n', $dayStart), (int)date('j', $dayStart), (int)date('Y', $dayStart));
            }
        }
        if ($dt < 1) {
            throw new ClientMcpToolError('invalid_time', t('client_mcp.err.time'));
        }
        if ($dt <= time()) {
            throw new ClientMcpToolError('slot_unavailable', t('client_mcp.err.slot'));
        }
        return $dt;
    }

    /**
     * Client-safe view of an order (specialist first name only).
     *
     * @param array<string,mixed> $order
     * @return array<string,mixed>
     */
    private static function bookingView(array $order): array
    {
        $dt = (int)($order['dt_event'] ?? 0);
        $state = OrdersRepository::clientState($order);
        $services = [];
        foreach ($order['cart_lines'] ?? [] as $line) {
            if (is_array($line)) {
                $services[] = [
                    'id' => (int)($line['id'] ?? 0),
                    'name' => (string)($line['name'] ?? ''),
                    'price' => (float)($line['price'] ?? 0),
                ];
            }
        }
        $operatorId = (int)($order['id_cosmetolog'] ?? 0);
        $card = $operatorId > 0 ? SitesBooking::operatorPublicCard($operatorId) : null;
        $total = (float)($order['summ'] ?? 0);

        return [
            'order_id' => (int)$order['id'],
            'state' => $state['state'],
            'cancellable' => $state['cancellable'],
            'start' => $dt > 0 ? date('c', $dt) : '',
            'date' => $dt > 0 ? date('Y-m-d', $dt) : '',
            'time' => $dt > 0 ? date('H:i', $dt) : '',
            'specialist' => $card !== null ? [
                'operator_id' => $operatorId,
                'name' => (string)$card['display_name'],
            ] : null,
            'services' => $services,
            'total' => $total,
            'currency' => ClientMcpConfig::currency(),
            'payment' => ClientMcpContext::payment($order, $total, $state['paid']),
        ];
    }
}
