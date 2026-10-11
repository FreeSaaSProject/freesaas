<?php
declare(strict_types=1);

/**
 * Staff 1C diagnostic console (legacy pages/1c.php parity).
 * Live API only — no stub sales; credentials from TerminalsConfig.
 */
final class OnecConsole
{
    public const SESSION_CLIENT = 'terminals_1c_client_id';
    public const SESSION_BRACELET = 'terminals_1c_bracelet';
    public const SESSION_SUMM = 'terminals_1c_summcheck';

    /**
     * @param array<string,mixed> $get
     * @param array<string,mixed> $post
     * @return array<string,mixed>
     */
    public static function run(string $mode, array $get, array $post, string $httpMethod): array
    {
        $mode = trim($mode);
        $httpMethod = strtoupper($httpMethod);
        $clientId = self::sessionClientId();
        $bracelet = self::sessionBracelet();
        $summCheck = self::sessionSummCheck();

        $state = [
            'live' => TerminalsConfig::onecLive(),
            'enabled' => TerminalsConfig::onecEnabled(),
            'ready' => TerminalsConfig::onecReady(),
            'mode' => $mode,
            'client_id' => $clientId,
            'bracelet' => $bracelet,
            'bracelet_disp' => self::braceletDisplay($bracelet),
            'summ_check' => $summCheck,
            'menu' => self::menuItems($clientId !== ''),
            'forms' => [],
            'tables' => [],
            'flashes' => [],
            'raw' => null,
            'config_error' => null,
        ];

        if (!$state['live']) {
            $state['config_error'] = $state['enabled']
                ? t('terminals.t.err.onec_config')
                : t('terminals.onec.err.disabled');

            return $state;
        }

        if ($httpMethod === 'POST' && isset($post['summ_check'])) {
            $summ = (int)$post['summ_check'];
            if ($summ < 0) {
                $summ = 0;
            }
            self::setSummCheck($summ);
            $state['summ_check'] = $summ;
            $state['flashes'][] = ['ok', t('terminals.onec.flash.summ_updated')];
            if ($mode === '' || $mode === 'price') {
                $mode = 'price';
                $state['mode'] = $mode;
            }
        }

        if ($mode === '') {
            return $state;
        }

        if ($httpMethod === 'POST' && ($mode === 'sale' || $mode === 'unsale' || $mode === 'cardtoticket')) {
            return self::runMutation($state, $mode, $post);
        }

        return self::runLookup($state, $mode, $get);
    }

    /**
     * @return list<array{mode:string,label:string,needs_client:bool}>
     */
    public static function menuItems(bool $hasClient): array
    {
        $items = [
            ['mode' => 'clientByCard', 'label' => t('terminals.onec.mode.clientByCard'), 'needs_client' => false],
            ['mode' => 'clientByCard1C', 'label' => t('terminals.onec.mode.clientByCard1C'), 'needs_client' => false],
            ['mode' => 'clientByPhone', 'label' => t('terminals.onec.mode.clientByPhone'), 'needs_client' => false],
            ['mode' => 'clientById', 'label' => t('terminals.onec.mode.clientById'), 'needs_client' => false],
            ['mode' => 'getticketsv2', 'label' => t('terminals.onec.mode.getticketsv2'), 'needs_client' => false],
        ];
        if ($hasClient) {
            $items = array_merge($items, [
                ['mode' => 'client', 'label' => t('terminals.onec.mode.client'), 'needs_client' => true],
                ['mode' => 'relatives', 'label' => t('terminals.onec.mode.relatives'), 'needs_client' => true],
                ['mode' => 'clientappointments', 'label' => t('terminals.onec.mode.clientappointments'), 'needs_client' => true],
                ['mode' => 'debtdetail', 'label' => t('terminals.onec.mode.debtdetail'), 'needs_client' => true],
                ['mode' => 'price', 'label' => t('terminals.onec.mode.price'), 'needs_client' => true],
                ['mode' => 'gettime', 'label' => t('terminals.onec.mode.gettime'), 'needs_client' => true],
                ['mode' => 'activeClients', 'label' => t('terminals.onec.mode.activeClients'), 'needs_client' => true],
                ['mode' => 'clubWorkload', 'label' => t('terminals.onec.mode.clubWorkload'), 'needs_client' => true],
                ['mode' => 'clubWorkloadDetail', 'label' => t('terminals.onec.mode.clubWorkloadDetail'), 'needs_client' => true],
            ]);
        }

        return $items;
    }

    public static function sessionClientId(): string
    {
        return trim((string)($_SESSION[self::SESSION_CLIENT] ?? ''));
    }

    public static function sessionBracelet(): string
    {
        return trim((string)($_SESSION[self::SESSION_BRACELET] ?? ''));
    }

    public static function sessionSummCheck(): int
    {
        return max(0, (int)($_SESSION[self::SESSION_SUMM] ?? 0));
    }

    public static function clearClient(): void
    {
        unset($_SESSION[self::SESSION_CLIENT], $_SESSION[self::SESSION_BRACELET]);
    }

    private static function setClient(string $clientId, string $bracelet): void
    {
        $_SESSION[self::SESSION_CLIENT] = $clientId;
        $_SESSION[self::SESSION_BRACELET] = $bracelet;
    }

    private static function setSummCheck(int $summ): void
    {
        $_SESSION[self::SESSION_SUMM] = $summ;
    }

    private static function braceletDisplay(string $raw): string
    {
        if ($raw === '') {
            return '';
        }
        if (strpos($raw, ',') !== false) {
            return $raw . ' (HEX: ' . OnecClient::braceletCardToUid($raw) . ')';
        }

        return OnecClient::braceletUidToCard($raw) . ' (HEX: ' . strtoupper($raw) . ')';
    }

    /**
     * @param array<string,mixed> $state
     * @param array<string,mixed> $post
     * @return array<string,mixed>
     */
    private static function runMutation(array $state, string $mode, array $post): array
    {
        $clientId = (string)$state['client_id'];
        if ($mode === 'sale') {
            if ($clientId === '') {
                $state['flashes'][] = ['err', t('terminals.onec.err.need_client')];

                return $state;
            }
            $purchaseId = trim((string)($post['purchase_id'] ?? ''));
            $price = (float)($post['price'] ?? 0);
            if ($purchaseId === '' || $price <= 0) {
                $state['flashes'][] = ['err', t('terminals.onec.err.sale_params')];

                return $state;
            }
            $res = OnecClient::sale($clientId, $purchaseId, $price, false);
            $state['raw'] = self::typedRaw('POST', 'payment/', [
                'client_id' => $clientId,
                'transaction_id' => 'sale',
                'cart' => [['purchase_id' => $purchaseId, 'count' => 1, 'price' => $price]],
            ], $res);
            if (!empty($res['ok'])) {
                $state['flashes'][] = ['ok', t('terminals.onec.flash.sale_ok', [
                    'id' => (string)($res['sale_id'] ?? ''),
                ])];
            } else {
                $state['flashes'][] = ['err', (string)($res['error'] ?? t('terminals.onec.err.call'))];
            }

            return $state;
        }

        if ($mode === 'unsale') {
            $documentId = trim((string)($post['document_id'] ?? ''));
            if ($documentId === '') {
                $state['flashes'][] = ['err', t('terminals.onec.err.document_id')];

                return $state;
            }
            $res = OnecClient::unsale($documentId, false);
            $state['raw'] = self::typedRaw('POST', 'payment/', [
                'transaction_id' => 'undosale_' . $documentId,
            ], $res);
            if (!empty($res['ok'])) {
                $state['flashes'][] = ['ok', t('terminals.onec.flash.unsale_ok')];
            } else {
                $state['flashes'][] = ['err', (string)($res['error'] ?? t('terminals.onec.err.call'))];
            }

            return $state;
        }

        // cardtoticket
        $client = trim((string)($post['client'] ?? ''));
        $package = trim((string)($post['package'] ?? ''));
        $card = trim((string)($post['card'] ?? ''));
        if ($client === '' || $package === '' || $card === '') {
            $state['flashes'][] = ['err', t('terminals.onec.err.cardtoticket')];

            return $state;
        }
        $raw = OnecClient::raw('POST', 'card_to_ticket/', [
            'client' => $client,
            'package' => $package,
            'card' => $card,
        ]);
        $state['raw'] = $raw;
        if (!empty($raw['ok'])) {
            $state['flashes'][] = ['ok', t('terminals.onec.flash.cardtoticket_ok')];
        } else {
            $state['flashes'][] = ['err', (string)($raw['error'] !== '' ? $raw['error'] : t('terminals.onec.err.call'))];
        }

        return $state;
    }

    /**
     * @param array<string,mixed> $state
     * @param array<string,mixed> $get
     * @return array<string,mixed>
     */
    private static function runLookup(array $state, string $mode, array $get): array
    {
        $clientId = (string)$state['client_id'];
        $cfg = TerminalsConfig::onec();
        $base = rtrim($cfg['url'], '/');

        if ($mode === 'clientByCard' || $mode === 'clientByCard1C' || $mode === 'clientBy1CCard') {
            $nfc = trim((string)($get['NFCUID'] ?? ''));
            $card = trim((string)($get['CARD'] ?? ''));
            $val = $mode === 'clientByCard' ? ($nfc !== '' ? $nfc : $card) : ($card !== '' ? $card : $nfc);
            if ($val === '') {
                $state['forms'][] = [
                    'kind' => 'get',
                    'mode' => $mode,
                    'fields' => $mode === 'clientByCard'
                        ? [['name' => 'NFCUID', 'label' => t('terminals.onec.field.nfc'), 'value' => '503AE7A6']]
                        : [['name' => 'CARD', 'label' => t('terminals.onec.field.card_1c'), 'value' => '218,37330']],
                ];

                return $state;
            }
            $cardNorm = $mode === 'clientByCard'
                ? OnecClient::normalizeCard($val)
                : (strpos($val, ',') !== false ? OnecClient::normalizeCard($val) : trim($val));
            $raw = OnecClient::raw('GET', 'client_by_card/?card=' . rawurlencode($cardNorm));
            $state['raw'] = $raw;
            self::maybeStoreClient($state, $raw, $cardNorm !== '' ? $cardNorm : $val);

            return $state;
        }

        if ($mode === 'clientByPhone') {
            $phone = trim((string)($get['PHONE'] ?? ''));
            if ($phone === '') {
                $state['forms'][] = [
                    'kind' => 'get',
                    'mode' => $mode,
                    'fields' => [['name' => 'PHONE', 'label' => t('terminals.onec.field.phone'), 'value' => '']],
                ];

                return $state;
            }
            $raw = OnecClient::raw('GET', 'client_by_phone/?phone=' . rawurlencode($phone));
            $state['raw'] = $raw;
            self::maybeStoreClient($state, $raw, '');

            return $state;
        }

        if ($mode === 'clientById') {
            $oneCid = trim((string)($get['ONECID'] ?? ''));
            if ($oneCid === '') {
                $state['forms'][] = [
                    'kind' => 'get',
                    'mode' => $mode,
                    'fields' => [['name' => 'ONECID', 'label' => t('terminals.onec.field.onec_id'), 'value' => '']],
                ];

                return $state;
            }
            $raw = OnecClient::raw('GET', 'client_by_id/?client_id=' . rawurlencode($oneCid));
            $state['raw'] = $raw;
            self::maybeStoreClient($state, $raw, '');

            return $state;
        }

        if ($mode === 'getticketsv2') {
            $phone = trim((string)($get['PHONE'] ?? ''));
            $card = trim((string)($get['CARD'] ?? ''));
            if ($phone === '' || $card === '') {
                $state['forms'][] = [
                    'kind' => 'get',
                    'mode' => $mode,
                    'fields' => [
                        ['name' => 'PHONE', 'label' => t('terminals.onec.field.phone'), 'value' => $phone],
                        ['name' => 'CARD', 'label' => t('terminals.onec.field.card'), 'value' => $card],
                    ],
                ];

                return $state;
            }
            $raw = OnecClient::raw('GET', 'Get_tickets_v2/?phone=' . rawurlencode($phone));
            $state['raw'] = $raw;
            $state['tables'][] = self::ticketsTable($raw, $card);

            return $state;
        }

        if ($clientId === '' && !in_array($mode, ['activeClients', 'clubWorkload', 'clubWorkloadDetail'], true)) {
            // Modes below need client (except workload which legacy listed under client menu)
            if (in_array($mode, ['client', 'relatives', 'clientappointments', 'debtdetail', 'price', 'gettime'], true)) {
                $state['flashes'][] = ['err', t('terminals.onec.err.need_client')];

                return $state;
            }
        }

        if ($mode === 'client') {
            if ($clientId === '') {
                $state['flashes'][] = ['err', t('terminals.onec.err.need_client')];

                return $state;
            }
            $state['raw'] = OnecClient::raw('GET', 'client_by_id/?client_id=' . rawurlencode($clientId));

            return $state;
        }

        if ($mode === 'relatives') {
            if ($clientId === '') {
                $state['flashes'][] = ['err', t('terminals.onec.err.need_client')];

                return $state;
            }
            $state['raw'] = OnecClient::raw('GET', 'relatives/?client_id=' . rawurlencode($clientId));

            return $state;
        }

        if ($mode === 'clientappointments') {
            if ($clientId === '') {
                $state['flashes'][] = ['err', t('terminals.onec.err.need_client')];

                return $state;
            }
            $q = 'client_appointments/?client_id=' . rawurlencode($clientId) . '&count=1&count_number=1&only_planned=0';
            $state['raw'] = OnecClient::raw('GET', $q);

            return $state;
        }

        if ($mode === 'gettime') {
            if ($clientId === '') {
                $state['flashes'][] = ['err', t('terminals.onec.err.need_client')];

                return $state;
            }
            $timeUrl = str_replace('api/v2', 'api_ipn/int', $base) . '/get_time/?client_id=' . rawurlencode($clientId);
            $state['raw'] = OnecClient::raw('GET', $timeUrl);

            return $state;
        }

        if ($mode === 'debtdetail') {
            if ($clientId === '') {
                $state['flashes'][] = ['err', t('terminals.onec.err.need_client')];

                return $state;
            }
            $raw = OnecClient::raw('GET', 'debt_detail/?client_id=' . rawurlencode($clientId));
            $state['raw'] = $raw;
            $state['tables'][] = self::debtTable($raw);

            return $state;
        }

        if ($mode === 'price') {
            if ($clientId === '') {
                $state['flashes'][] = ['err', t('terminals.onec.err.need_client')];

                return $state;
            }
            $raw = OnecClient::raw('GET', 'price_list/?client_id=' . rawurlencode($clientId));
            $state['raw'] = $raw;
            $state['tables'][] = self::priceTable($raw, (int)$state['summ_check']);
            $state['forms'][] = [
                'kind' => 'summ',
                'mode' => 'price',
                'value' => (int)$state['summ_check'],
            ];

            return $state;
        }

        if ($mode === 'activeClients') {
            $state['raw'] = OnecClient::raw('GET', 'active_clients/');

            return $state;
        }

        if ($mode === 'clubWorkload') {
            $url = str_replace('/v2', '/v3', $base) . '/club_workload';
            $state['raw'] = OnecClient::raw('GET', $url);

            return $state;
        }

        if ($mode === 'clubWorkloadDetail') {
            $url = str_replace('/v2', '/v3', $base) . '/club_workload_detail/';
            $state['raw'] = OnecClient::raw('GET', $url);

            return $state;
        }

        $state['flashes'][] = ['err', t('terminals.onec.err.unknown_mode', ['mode' => $mode])];

        return $state;
    }

    /**
     * @param array<string,mixed> $state
     * @param array<string,mixed> $raw
     */
    private static function maybeStoreClient(array &$state, array $raw, string $bracelet): void
    {
        $decoded = $raw['decoded'] ?? null;
        if (!is_array($decoded)) {
            return;
        }
        $data = is_array($decoded['data'] ?? null) ? $decoded['data'] : $decoded;
        $cid = trim((string)($data['client_id'] ?? $data['ClientId'] ?? ''));
        if ($cid === '' || $cid === '0000') {
            return;
        }
        self::setClient($cid, $bracelet);
        $state['client_id'] = $cid;
        $state['bracelet'] = $bracelet;
        $state['bracelet_disp'] = self::braceletDisplay($bracelet);
        $state['menu'] = self::menuItems(true);
        $state['flashes'][] = ['ok', t('terminals.onec.flash.client_saved')];
    }

    /**
     * @param array<string,mixed> $typedRes
     * @param array<string,mixed>|null $body
     * @return array<string,mixed>
     */
    private static function typedRaw(string $method, string $path, ?array $body, array $typedRes): array
    {
        $cfg = TerminalsConfig::onec();
        $params = $typedRes['parameters'] ?? null;
        $decoded = is_array($params) ? $params : ($typedRes['raw'] ?? null);
        if (isset($typedRes['raw']) && is_string($typedRes['raw'])) {
            $try = json_decode($typedRes['raw'], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $decoded = $try;
            }
        }

        return [
            'ok' => !empty($typedRes['ok']),
            'http' => 0,
            'url' => rtrim($cfg['url'], '/') . '/' . ltrim($path, '/'),
            'method' => $method,
            'headers_meta' => [
                'Accept: application/json',
                'Authorization: Basic ***',
                'terminalid:' . $cfg['terminal'],
                'apikey: ***',
            ],
            'body_sent' => $body,
            'raw' => is_string($typedRes['raw'] ?? null)
                ? (string)$typedRes['raw']
                : (string)json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            'decoded' => $decoded,
            'error' => (string)($typedRes['error'] ?? ''),
        ];
    }

    /**
     * @param array<string,mixed> $raw
     * @return array{type:string,title:string,headers:list<string>,rows:list<list<mixed>>}
     */
    private static function debtTable(array $raw): array
    {
        $rows = [];
        $decoded = $raw['decoded'] ?? null;
        $list = [];
        if (is_array($decoded)) {
            $list = is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
            if ($list === [] && isset($decoded[0])) {
                $list = $decoded;
            }
        }
        foreach ($list as $item) {
            if (!is_array($item)) {
                continue;
            }
            $docId = (string)($item['document_id'] ?? '');
            $rows[] = [
                $docId,
                (string)($item['date'] ?? ''),
                (string)($item['operation'] ?? ''),
                (string)($item['cost'] ?? ''),
                $docId,
            ];
        }

        return [
            'type' => 'debt',
            'title' => t('terminals.onec.table.debt'),
            'headers' => [
                t('terminals.onec.col.document_id'),
                t('terminals.onec.col.date'),
                t('terminals.onec.col.operation'),
                t('terminals.onec.col.cost'),
                t('terminals.onec.col.unsale'),
            ],
            'rows' => $rows,
        ];
    }

    /**
     * @param array<string,mixed> $raw
     * @return array{type:string,title:string,headers:list<string>,rows:list<list<mixed>>,summ_check:int}
     */
    private static function priceTable(array $raw, int $summCheck): array
    {
        $map = self::catalogOnecMap();
        $rows = [];
        $decoded = $raw['decoded'] ?? null;
        $list = [];
        if (is_array($decoded) && isset($decoded['Parameters']) && is_array($decoded['Parameters'])) {
            $list = $decoded['Parameters'];
        }
        foreach ($list as $item) {
            if (!is_array($item)) {
                continue;
            }
            $product1c = (string)($item['ID'] ?? '');
            $title = (string)($item['Title'] ?? '');
            $price = (float)($item['Price'] ?? 0);
            $local = $map[$product1c] ?? null;
            $localId = $local !== null ? (string)$local['id'] : '—';
            $priceWarn = '';
            if ($local !== null && abs((float)$local['price'] - $price) > 0.009) {
                $priceWarn = t('terminals.onec.price_mismatch', ['price' => (string)$local['price']]);
            }
            $sendPrice = $summCheck > 0 ? (float)$summCheck : $price;
            $rows[] = [
                $product1c,
                $localId,
                $title,
                $price,
                $priceWarn,
                $sendPrice,
                $summCheck > 0,
            ];
        }

        return [
            'type' => 'price',
            'title' => t('terminals.onec.table.price'),
            'headers' => [
                t('terminals.onec.col.onec_id'),
                t('terminals.onec.col.local_id'),
                t('terminals.onec.col.name'),
                t('terminals.onec.col.price'),
                t('terminals.onec.col.sale'),
            ],
            'rows' => $rows,
            'summ_check' => $summCheck,
        ];
    }

    /**
     * @param array<string,mixed> $raw
     * @return array{type:string,title:string,headers:list<string>,rows:list<list<mixed>>}
     */
    private static function ticketsTable(array $raw, string $card): array
    {
        $rows = [];
        $decoded = $raw['decoded'] ?? null;
        $list = [];
        if (is_array($decoded) && isset($decoded['Parameters']) && is_array($decoded['Parameters'])) {
            $list = $decoded['Parameters'];
        }
        foreach ($list as $item) {
            if (!is_array($item)) {
                continue;
            }
            if (empty($item['active'])) {
                continue;
            }
            $rows[] = [
                (string)($item['ticket'] ?? ''),
                (string)($item['client'] ?? ''),
                (string)($item['package'] ?? ''),
                $card,
            ];
        }

        return [
            'type' => 'tickets',
            'title' => t('terminals.onec.table.tickets'),
            'headers' => [
                t('terminals.onec.col.ticket'),
                t('terminals.onec.col.client'),
                t('terminals.onec.col.package'),
                t('terminals.onec.col.link_card'),
            ],
            'rows' => $rows,
        ];
    }

    /**
     * @return array<string,array{id:int,price:float}>
     */
    private static function catalogOnecMap(): array
    {
        if (!Config::moduleEnabled('catalog')) {
            return [];
        }
        if (!Database::tableExists('base_products') || !Database::columnExists('base_products', 'onec_id')) {
            return [];
        }
        $st = Database::pdo()->query(
            "SELECT id, price, onec_id FROM base_products WHERE onec_id <> '' AND is_material = 0"
        );
        if ($st === false) {
            return [];
        }
        $map = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            if (!is_array($row)) {
                continue;
            }
            $oid = trim((string)($row['onec_id'] ?? ''));
            if ($oid === '') {
                continue;
            }
            $map[$oid] = [
                'id' => (int)$row['id'],
                'price' => (float)($row['price'] ?? 0),
            ];
        }

        return $map;
    }
}
