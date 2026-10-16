<?php
declare(strict_types=1);

/**
 * Drop-in legacy HTTP API (api_termy.php / api.php) over FreeSaaS booking/orders/terminals.
 * Public routes; optional legacy_api_key; Termy shape for Volga Termy clients.
 */
final class LikespaLegacyApi
{
    public static function termyEnabled(): bool
    {
        return LikespaConfig::flag('legacy_api_enabled', false)
            && class_exists(BookingBook::class, false)
            && class_exists(OrdersRepository::class, false);
    }

    public static function assertKey(): void
    {
        $key = trim(LikespaConfig::string('legacy_api_key', ''));
        if ($key === '') {
            return;
        }
        $got = (string)($_SERVER['HTTP_X_LIKESPA_API_KEY'] ?? $_GET['api_key'] ?? $_GET['key'] ?? '');
        if (!hash_equals($key, $got)) {
            self::json(['error' => 'unauthorized'], 401);
        }
    }

    public static function dispatchTermy(): void
    {
        self::assertKey();
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            self::json(['error' => 'Method Not Allowed'], 405);
        }
        $endpoint = trim((string)($_GET['endpoint'] ?? ''));
        $cat = LikespaConfig::int('legacy_api_termy_category', 16);
        switch ($endpoint) {
            case 'health':
                self::json([
                    'status' => 'ok',
                    'timestamp' => time(),
                    'php_version' => PHP_VERSION,
                    'server_time' => date('c'),
                ]);
                break;
            case 'get_categories':
                self::json(self::termyCategories($cat));
                break;
            case 'get_services':
                self::json(self::termyServices($cat));
                break;
            case 'get_personnel':
                self::json(self::termyPersonnel($cat));
                break;
            case 'get_client':
                self::json(self::getClient());
                break;
            case 'book_service':
                self::json(self::termyBook($cat, 'api_termy'));
                break;
            case 'get_my_orders':
                self::json(self::myOrders());
                break;
            case 'cancel_order':
                self::json(self::cancelOrder());
                break;
            default:
                self::json(['error' => 'Not Found'], 404);
        }
    }

    public static function dispatchFull(): void
    {
        self::assertKey();
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            self::json(['error' => 'Method Not Allowed'], 405);
        }
        $endpoint = trim((string)($_GET['endpoint'] ?? ''));
        switch ($endpoint) {
            case 'health':
                self::json([
                    'status' => 'ok',
                    'timestamp' => time(),
                    'php_version' => PHP_VERSION,
                    'server_time' => date('c'),
                ]);
                break;
            case 'get_categories':
                self::json(self::allCategories());
                break;
            case 'get_services':
                self::json(self::allServices());
                break;
            case 'get_personnel':
                self::json(self::termyPersonnel(0));
                break;
            case 'get_client':
                self::json(self::getClient());
                break;
            case 'book_service':
                self::json(self::fullBook());
                break;
            case 'get_my_orders':
                self::json(self::myOrders());
                break;
            case 'cancel_order':
                self::json(self::cancelOrder());
                break;
            case 'cancel_booking':
                self::json(self::cancelBooking());
                break;
            case 'free_terminal':
                self::json(self::freeTerminal());
                break;
            case 'get_employee_status':
                self::json(self::employeeStatus());
                break;
            default:
                // Termy-compatible subset also works on api.php
                if ($endpoint === '') {
                    self::json(['error' => 'Not Found'], 404);
                }
                self::json(['error' => 'Not Found'], 404);
        }
    }

    public static function dispatchApi1c(): void
    {
        self::assertKey();
        if (!class_exists(OnecClient::class, false)) {
            self::json(['error' => 'terminals unavailable'], 503);
        }
        $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
        $path = (string)(parse_url($uri, PHP_URL_PATH) ?? '');
        $path = preg_replace('#^/api1c\.php#', '', $path) ?? '';
        $query = (string)(parse_url($uri, PHP_URL_QUERY) ?? '');
        $rel = $path . ($query !== '' ? '?' . $query : '');
        if ($rel === '' || $rel === '?') {
            $rel = '/' . ltrim((string)($_SERVER['PATH_INFO'] ?? ''), '/');
            if ($query !== '' && strpos($rel, '?') === false) {
                $rel .= '?' . $query;
            }
        }
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $body = in_array($method, ['POST', 'PUT', 'PATCH'], true)
            ? (string)file_get_contents('php://input')
            : null;
        $res = OnecClient::proxy($method, $rel === '' ? '/' : $rel, $body);
        http_response_code((int)($res['http'] ?? 502));
        header('Content-Type: application/json; charset=utf-8');
        if (($res['body'] ?? '') !== '') {
            echo $res['body'];
        } else {
            echo json_encode([
                'error' => '1C server ERROR',
                'message' => (string)($res['error'] ?? ''),
            ], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    /** @return array<string,mixed> */
    public static function diagnose(): array
    {
        $issues = [];
        $enabled = LikespaConfig::flag('legacy_api_enabled', false);
        if (!$enabled) {
            $issues[] = 'legacy_api_disabled';
        }
        if (!class_exists(BookingBook::class, false)) {
            $issues[] = 'booking_missing';
        }
        if (!class_exists(OrdersRepository::class, false)) {
            $issues[] = 'orders_missing';
        }
        if (!class_exists(ClientsRepository::class, false)) {
            $issues[] = 'clients_missing';
        }
        $key = trim(LikespaConfig::string('legacy_api_key', ''));
        return [
            'legacy_api_enabled' => $enabled,
            'legacy_api_key_set' => $key !== '',
            'termy_category' => LikespaConfig::int('legacy_api_termy_category', 16),
            'routes' => ['/api_termy.php', '/api.php', '/api1c.php'],
            'soft_deps' => [
                'booking' => class_exists(BookingBook::class, false),
                'orders' => class_exists(OrdersRepository::class, false),
                'clients' => class_exists(ClientsRepository::class, false),
                'terminals' => class_exists(OnecClient::class, false),
                'procedures' => class_exists(ProceduresRepository::class, false),
            ],
            'issues' => $issues,
        ];
    }

    /** @param array<string,mixed> $payload */
    private static function json(array $payload, int $code = 200): void
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        $flags = JSON_UNESCAPED_UNICODE;
        if (!empty($_GET['debug'])) {
            $flags |= JSON_PRETTY_PRINT;
        }
        echo json_encode($payload, $flags);
        exit;
    }

    /** @return array<string,mixed> */
    private static function termyCategories(int $cat): array
    {
        $name = 'Termy';
        if (class_exists(CategoriesRepository::class, false)) {
            $c = CategoriesRepository::find($cat);
            if ($c !== null) {
                $name = (string)($c['name'] ?? $name);
            }
        }
        return ['categories' => [['id' => (string)$cat, 'name' => $name]]];
    }

    /** @return array<string,mixed> */
    private static function allCategories(): array
    {
        if (!class_exists(CategoriesRepository::class, false)) {
            return ['categories' => []];
        }
        $res = CategoriesRepository::list(['limit' => 500, 'offset' => 0]);
        $out = [];
        foreach ($res['items'] ?? [] as $c) {
            if ((int)($c['hidden'] ?? 0) > 0) {
                continue;
            }
            $out[] = [
                'id' => (string)(int)($c['id'] ?? 0),
                'name' => (string)($c['name'] ?? ''),
            ];
        }
        return ['categories' => $out];
    }

    /** @return array<string,mixed> */
    private static function termyServices(int $cat): array
    {
        $date = trim((string)($_GET['date'] ?? date('Y-m-d')));
        $services = [];
        if (!class_exists(BookingCatalog::class, false)) {
            return ['services' => [], 'date' => $date];
        }
        foreach (BookingCatalog::listServices(1000) as $p) {
            if ($cat > 0 && (int)($p['category'] ?? 0) !== $cat) {
                continue;
            }
            $id = (int)($p['id'] ?? 0);
            $price = (float)($p['price3'] ?? 0);
            if ($price <= 0) {
                $price = (float)($p['price'] ?? 0);
            }
            $services[] = [
                'id' => (string)$id,
                'name' => (string)($p['name'] ?? ''),
                'price' => $price,
                'duration' => (int)($p['time_sec'] ?? 0),
                'category_id' => (string)(int)($p['category'] ?? 0),
            ];
        }
        return ['services' => $services, 'date' => $date];
    }

    /** @return array<string,mixed> */
    private static function allServices(): array
    {
        return self::termyServices(0);
    }

    /** @return array<string,mixed> */
    private static function termyPersonnel(int $cat): array
    {
        $date = trim((string)($_GET['date'] ?? ''));
        if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return [
                'error' => 'date required (Y-m-d)',
                'example' => '?endpoint=get_personnel&date=' . date('Y-m-d') . '&service_id=1',
            ];
        }
        $serviceId = (int)($_GET['service_id'] ?? 0);
        if ($serviceId < 1) {
            $services = self::termyServices($cat)['services'] ?? [];
            $serviceId = (int)(($services[0]['id'] ?? 0));
        }
        if ($serviceId < 1) {
            return ['massage_therapists' => [], 'equipment' => [], 'administrators' => []];
        }
        if ($cat > 0 && class_exists(ProductsRepository::class, false)) {
            $p = ProductsRepository::find($serviceId);
            if ($p === null || (int)($p['category'] ?? 0) !== $cat) {
                return ['error' => 'service_id not in catalog ' . $cat];
            }
        }
        if (!class_exists(BookingAvailability::class, false)) {
            return ['massage_therapists' => [], 'equipment' => [], 'administrators' => []];
        }
        $leadSec = $cat > 0 ? LikespaConfig::leadSecTermy() : LikespaConfig::leadSecApi();
        try {
            $avail = BookingAvailability::forDay($date, [$serviceId], null, null, false, $leadSec);
        } catch (Throwable $e) {
            return ['error' => $e->getMessage(), 'massage_therapists' => []];
        }
        $therapists = [];
        foreach ($avail['operators'] ?? [] as $op) {
            if (empty($op['available']) && ($op['slots'] ?? []) === []) {
                continue;
            }
            $rawSlots = array_map('intval', $op['slots'] ?? []);
            $slots = class_exists(BookingSlots::class, false)
                ? BookingSlots::filterListSlots($rawSlots)
                : $rawSlots;
            $slotsHhmm = [];
            foreach ($slots as $ts) {
                $slotsHhmm[] = date('H:i', (int)$ts);
            }
            $nameParts = preg_split('/\s+/', trim((string)($op['name'] ?? '')), 3) ?: [];
            $therapists[] = [
                'id' => (string)(int)$op['id'],
                'surname' => (string)($nameParts[0] ?? ''),
                'name' => (string)($nameParts[1] ?? ($nameParts[0] ?? '')),
                'patronimic' => (string)($nameParts[2] ?? ''),
                'gender' => '',
                'bday' => '',
                'image_url' => '',
                'description' => '',
                'available_slots' => $slotsHhmm,
                'products' => $slotsHhmm !== [] ? [(string)$serviceId => $slotsHhmm[0]] : [],
                'startTime' => $slotsHhmm[0] ?? '',
                'endTime' => $slotsHhmm !== [] ? $slotsHhmm[count($slotsHhmm) - 1] : '',
            ];
        }
        return [
            'massage_therapists' => $therapists,
            'equipment' => [],
            'administrators' => [],
        ];
    }

    /** @return array<string,mixed> */
    private static function getClient(): array
    {
        $phone = preg_replace('/\D+/', '', (string)($_GET['phone'] ?? '')) ?? '';
        if ($phone === '') {
            return ['error' => 'Missing required parameter: phone'];
        }
        if (strlen($phone) === 11 && $phone[0] === '8') {
            $phone = '7' . substr($phone, 1);
        }
        if (!class_exists(ClientsRepository::class, false)) {
            return ['error' => 'clients unavailable'];
        }
        $name = trim((string)($_GET['name'] ?? ''));
        $client = ClientsRepository::findByPhoneDigits($phone);
        if ($client === null) {
            $id = ClientsRepository::create(ClientsRepository::dataFromPost([
                'tel' => $phone,
                'name' => $name !== '' ? $name : $phone,
                'enabled' => '1',
            ]));
            $client = ClientsRepository::find($id);
        } elseif ($name !== '' && trim((string)($client['name'] ?? '')) === '') {
            $data = ClientsRepository::dataFromPost([
                'tel' => (string)($client['tel'] ?? $phone),
                'name' => $name,
                'mail' => (string)($client['mail'] ?? ''),
                'enabled' => !empty($client['enabled']) ? '1' : '0',
                'status_id' => (int)($client['status_id'] ?? 0),
            ]);
            ClientsRepository::update((int)$client['id'], $data);
            $client = ClientsRepository::find((int)$client['id']);
        }
        if ($client === null) {
            return ['error' => 'client create failed'];
        }
        $orders = [];
        if (class_exists(OrdersRepository::class, false)) {
            foreach (OrdersRepository::listFutureByClient((int)$client['id'], 20) as $o) {
                $orders[(string)$o['id']] = [
                    'id' => (string)$o['id'],
                    'dt_event' => (int)$o['dt_event'],
                    'status' => (string)$o['status'],
                    'summ' => (float)$o['summ'],
                ];
            }
        }
        return [
            'id' => (string)(int)$client['id'],
            'name' => (string)($client['name'] ?? ''),
            'gender' => (string)($client['gender'] ?? ''),
            'bday' => (string)($client['bday'] ?? ''),
            'status' => (string)($client['status_id'] ?? $client['status'] ?? '0'),
            'BONUS' => (float)($client['bonus'] ?? 0),
            'procedures' => new stdClass(),
            'orders' => $orders === [] ? new stdClass() : $orders,
            '1C_ID' => '',
            '1C_DT_VALID' => '',
            '1C_BRACELET' => '',
            'ID_USER' => '',
        ];
    }

    /**
     * @param 'api_termy'|'api' $source
     * @return array<string,mixed>
     */
    private static function termyBook(int $cat, string $source = 'api_termy'): array
    {
        $serviceId = (int)($_GET['service_id'] ?? 0);
        $clientId = (int)($_GET['client_id'] ?? 0);
        $specialistId = (int)($_GET['specialist_id'] ?? $_GET['adminId'] ?? 0);
        $hour = (int)($_GET['hour'] ?? -1);
        $minute = (int)($_GET['minute'] ?? -1);
        $date = trim((string)($_GET['date'] ?? ''));
        $nowHm = date('H:i:s');
        if ($serviceId < 1 || $clientId < 1 || $specialistId < 1 || $hour < 0 || $minute < 0 || $date === '') {
            return [
                'error' => 'Missing required parameters: service_id, client_id, specialist_id, hour, minute, date',
                'booked' => 0,
            ];
        }
        if ($cat > 0 && class_exists(ProductsRepository::class, false)) {
            $p = ProductsRepository::find($serviceId);
            if ($p === null || (int)($p['category'] ?? 0) !== $cat) {
                return ['error' => 'service_id not in catalog ' . $cat, 'booked' => 0];
            }
        }
        $dayStart = strtotime($date . ' 00:00:00');
        if ($dayStart === false) {
            return ['error' => 'invalid date', 'booked' => 0, 'currentTime' => $nowHm];
        }
        $dtStart = (int)mktime($hour, $minute, 0, (int)date('n', $dayStart), (int)date('j', $dayStart), (int)date('Y', $dayStart));
        $client = class_exists(ClientsRepository::class, false) ? ClientsRepository::find($clientId) : null;
        if ($client === null) {
            return ['error' => 'client not found', 'booked' => 0, 'currentTime' => $nowHm];
        }
        $phone = preg_replace('/\D+/', '', (string)($client['tel'] ?? '')) ?? '';
        $bookIn = [
            'phone' => $phone,
            'client_id' => $clientId,
            'client_name' => (string)($client['name'] ?? ''),
            'product_ids' => [$serviceId],
            'operator_id' => $specialistId,
            'dt_start' => $dtStart,
            'source' => $source === 'api' ? 'api' : 'api_termy',
            'lead_sec' => $source === 'api'
                ? LikespaConfig::leadSecApi()
                : LikespaConfig::leadSecTermy(),
        ];
        if (isset($_GET['price']) && $_GET['price'] !== '') {
            $price = (float)$_GET['price'];
            $bookIn['cart_lines'] = [[
                'id' => $serviceId,
                'name' => '',
                'price' => $price,
                'price_original' => $price,
            ]];
        }
        try {
            $book = BookingBook::createOrder($bookIn);
        } catch (Throwable $e) {
            return [
                'currentTime' => $nowHm,
                'error' => $e->getMessage(),
                'booked' => 0,
                'reserved' => 0,
                'result' => [
                    'executable' => 0,
                    'selected_specialistId' => 0,
                    'selected_hour' => 0,
                    'selected_minute' => 0,
                    'changes' => [],
                    'rus' => $e->getMessage(),
                ],
                'totalTime' => 0,
                'updateInfo' => [],
            ];
        }
        $duration = 3600;
        if (class_exists(ProductsRepository::class, false)) {
            $p = ProductsRepository::find($serviceId);
            if ($p !== null) {
                $duration = max(300, (int)($p['time_sec'] ?? 3600));
            }
        }
        return [
            'currentTime' => $nowHm,
            'error' => '',
            'booked' => 1,
            'reserved' => 0,
            'result' => [
                'executable' => 1,
                'selected_specialistId' => (string)$specialistId,
                'selected_hour' => $hour,
                'selected_minute' => $minute,
                'changes' => new stdClass(),
                'order_id' => (int)$book['order_id'],
                'rus' => 'OK',
            ],
            'totalTime' => $duration,
            'updateInfo' => [],
        ];
    }

    /** @return array<string,mixed> */
    private static function fullBook(): array
    {
        $bookByOrder = !empty($_GET['bookByOrder']) || (string)($_GET['strict'] ?? '') === '1';
        $terminalId = (int)($_GET['terminalId'] ?? $_GET['terminalid'] ?? 0);
        $documentId = trim((string)($_GET['document_id'] ?? ''));
        $braceletId = trim((string)($_GET['bracelet_id'] ?? $_GET['braceletid'] ?? ''));
        if (!$bookByOrder && $terminalId > 0 && class_exists(TerminalBooking::class, false)) {
            $serviceId = (int)($_GET['service_id'] ?? 0);
            $adminId = (int)($_GET['adminId'] ?? $_GET['specialist_id'] ?? 0);
            $clientId = (int)($_GET['client_id'] ?? 0);
            $date = trim((string)($_GET['date'] ?? date('Y-m-d')));
            $hour = (int)($_GET['hour'] ?? date('G'));
            $minute = (int)($_GET['minute'] ?? 0);
            $dayStart = strtotime($date . ' 00:00:00');
            $dtStart = $dayStart !== false
                ? (int)mktime($hour, $minute, 0, (int)date('n', $dayStart), (int)date('j', $dayStart), (int)date('Y', $dayStart))
                : time();
            $bookIn = [
                'product_id' => $serviceId,
                'operator_id' => $adminId,
                'admin_id' => $adminId,
                'terminal_id' => $terminalId,
                'dt_start' => $dtStart,
                'client_id' => $clientId,
                'onec_client_id' => $documentId !== '' ? $documentId : '',
                'onec_bracelet' => $braceletId,
                'disable_1c' => !empty($_GET['disable1C']),
            ];
            if (isset($_GET['price']) && $_GET['price'] !== '') {
                $bookIn['price'] = (float)$_GET['price'];
            }
            $res = TerminalBooking::book($bookIn);
            return [
                'currentTime' => date('H:i:s'),
                'error' => empty($res['ok']) ? (string)($res['error'] ?? 'book failed') : '',
                'booked' => !empty($res['ok']) ? 1 : 0,
                'reserved' => 0,
                'result' => $res,
                'totalTime' => 0,
                'updateInfo' => [],
            ];
        }
        return self::termyBook(0, 'api');
    }

    /** @return array<string,mixed> */
    private static function myOrders(): array
    {
        $clientId = (int)($_GET['client_id'] ?? 0);
        if ($clientId < 1) {
            return ['error' => 'client_id required', 'orders' => []];
        }
        if (!class_exists(OrdersRepository::class, false)) {
            return ['error' => 'orders unavailable', 'orders' => []];
        }
        $orders = [];
        foreach (OrdersRepository::listFutureByClient($clientId, 100) as $o) {
            $orders[] = [
                'id' => (string)$o['id'],
                'uid' => (string)($o['uid'] ?? ''),
                'dt_event' => (int)$o['dt_event'],
                'id_cosmetolog' => (int)$o['id_cosmetolog'],
                'status' => (string)$o['status'],
                'summ' => (float)$o['summ'],
                'cart' => $o['cart_lines'] ?? [],
            ];
        }
        return ['orders' => $orders];
    }

    /** @return array<string,mixed> */
    private static function cancelOrder(): array
    {
        $orderId = (int)($_GET['order_id'] ?? $_GET['booking_id'] ?? 0);
        $clientId = (int)($_GET['client_id'] ?? 0);
        if ($orderId < 1 || $clientId < 1) {
            return ['error' => 'order_id and client_id required', 'cancelled' => 0];
        }
        if (!class_exists(OrdersRepository::class, false)) {
            return ['error' => 'orders unavailable', 'cancelled' => 0];
        }
        $reason = trim((string)($_GET['reason'] ?? ''));
        $res = OrdersRepository::cancelForClient($orderId, $clientId, $reason);
        return [
            'cancelled' => !empty($res['ok']) ? 1 : 0,
            'error' => empty($res['ok']) ? (string)($res['error'] ?? '') : '',
        ];
    }

    /** @return array<string,mixed> */
    private static function cancelBooking(): array
    {
        $bookingId = (int)($_GET['booking_id'] ?? $_GET['procedure_id'] ?? 0);
        if ($bookingId < 1 || !class_exists(TerminalBooking::class, false)) {
            return ['error' => 'booking_id required', 'cancelled' => 0];
        }
        $res = TerminalBooking::cancel($bookingId, false, !empty($_GET['disable1C']));
        return [
            'cancelled' => !empty($res['ok']) ? 1 : 0,
            'error' => empty($res['ok']) ? (string)($res['error'] ?? '') : '',
        ];
    }

    /** @return array<string,mixed> */
    private static function freeTerminal(): array
    {
        $terminalId = (int)($_GET['terminalId'] ?? $_GET['terminalid'] ?? 0);
        if ($terminalId < 1 || !class_exists(TerminalsRepository::class, false)) {
            return ['error' => 'terminalId required', 'freed' => 0];
        }
        TerminalsRepository::clearExecutorLock($terminalId);
        return ['freed' => 1];
    }

    /** @return array<string,mixed> */
    private static function employeeStatus(): array
    {
        $phone = preg_replace('/\D+/', '', (string)($_GET['phone'] ?? '')) ?? '';
        if ($phone === '' || !class_exists(UsersRepository::class, false)) {
            return ['error' => 'phone required'];
        }
        $users = UsersRepository::list(['q' => $phone, 'limit' => 5, 'offset' => 0]);
        $item = $users['items'][0] ?? null;
        if ($item === null) {
            return ['found' => 0];
        }
        return [
            'found' => 1,
            'id' => (int)$item['id'],
            'name' => (string)($item['name'] ?? ''),
            'login' => (string)($item['login'] ?? ''),
        ];
    }
}
