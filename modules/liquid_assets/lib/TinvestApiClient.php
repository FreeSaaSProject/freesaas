<?php
declare(strict_types=1);

/**
 * T-Invest Open API (REST) client — port of oper show_liquid_assets helpers.
 */
final class TinvestApiClient
{
    public const API_BASE = 'https://invest-public-api.tbank.ru/rest/';

    /** @var array{n:int,sec:float,by:array<string,array{n:int,sec:float}>} */
    private static $stats = ['n' => 0, 'sec' => 0.0, 'by' => []];

    /** @var array<string,array{name:string,ticker:string,instrument_type:string}> */
    private static $instrumentCache = [];

    /** @var array<string,string> */
    private static $bondNoteCache = [];

    /** @return array{n:int,sec:float,by:array<string,array{n:int,sec:float}>} */
    public static function stats(): array
    {
        return self::$stats;
    }

    public static function resetStats(): void
    {
        self::$stats = ['n' => 0, 'sec' => 0.0, 'by' => []];
    }

    /** @param mixed $money */
    public static function moneyToFloat($money): float
    {
        if (!is_array($money)) {
            return 0.0;
        }
        $units = isset($money['units']) ? (float)$money['units'] : 0.0;
        $nano = isset($money['nano']) ? (float)$money['nano'] : 0.0;

        return $units + $nano / 1000000000.0;
    }

    /** @param mixed $q */
    public static function quotationToFloat($q): float
    {
        return self::moneyToFloat($q);
    }

    /**
     * @param array<string,mixed>|null $body
     * @return array{ok:bool,data:mixed,error:string,http:int}
     */
    public static function post(string $path, string $token, ?array $body = null): array
    {
        $url = self::API_BASE . ltrim($path, '/');
        $payload = ($body === null || $body === []) ? '{}' : (string)json_encode($body);
        $t0 = microtime(true);
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $token,
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        $raw = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);
        $dt = microtime(true) - $t0;
        self::$stats['n']++;
        self::$stats['sec'] += $dt;
        $short = $path;
        if (($p = strrpos($path, '/')) !== false) {
            $short = substr($path, $p + 1);
        }
        if (!isset(self::$stats['by'][$short])) {
            self::$stats['by'][$short] = ['n' => 0, 'sec' => 0.0];
        }
        self::$stats['by'][$short]['n']++;
        self::$stats['by'][$short]['sec'] += $dt;

        if ($raw === false) {
            return ['ok' => false, 'data' => null, 'error' => 'curl: ' . $cerr, 'http' => $http];
        }
        $data = json_decode((string)$raw, true);
        if ($http < 200 || $http >= 300) {
            $msg = substr((string)$raw, 0, 400);
            if (is_array($data)) {
                if (!empty($data['message'])) {
                    $msg = (string)$data['message'];
                } elseif (!empty($data['description'])) {
                    $msg = (string)$data['description'];
                } elseif (isset($data['code'])) {
                    $msg = 'code=' . $data['code'] . ' ' . $raw;
                }
            }

            return ['ok' => false, 'data' => $data, 'error' => 'HTTP ' . $http . ': ' . $msg, 'http' => $http];
        }

        return ['ok' => true, 'data' => $data, 'error' => '', 'http' => $http];
    }

    public static function mapInstrumentType(string $type): string
    {
        $t = strtolower($type);
        if ($t === 'bond' || $t === 'instrumenttypebond') {
            return 'bond';
        }
        if ($t === 'share' || $t === 'instrumenttypeshare' || $t === 'stock') {
            return 'stock';
        }
        if ($t === 'etf' || $t === 'instrumenttypeetf') {
            return 'etf';
        }
        if ($t === 'currency' || $t === 'instrumenttypecurrency') {
            return 'currency';
        }
        if ($t === 'futures' || $t === 'instrumenttypefutures' || $t === 'future'
            || $t === 'option' || $t === 'instrumenttypeoption' || $t === 'options') {
            return 'futures';
        }
        if ($t === 'clearing_certificate' || $t === 'sp' || strpos($t, 'money') !== false) {
            return 'money';
        }

        return 'other';
    }

    public static function assetClassLabel(string $class): string
    {
        $key = 'liquid_assets.report.class.' . $class;
        $label = t($key);

        return $label !== $key ? $label : $class;
    }

    /**
     * @return array{ok:bool,accounts:list<array{id:string,name:string,type:string,status:string}>,error:string}
     */
    public static function listAccounts(string $token): array
    {
        $token = trim($token);
        if ($token === '') {
            return ['ok' => false, 'accounts' => [], 'error' => 'empty token'];
        }
        $api = self::post(
            'tinkoff.public.invest.api.contract.v1.UsersService/GetAccounts',
            $token,
            ['status' => 'ACCOUNT_STATUS_ALL']
        );
        if (!$api['ok']) {
            return ['ok' => false, 'accounts' => [], 'error' => $api['error']];
        }
        $list = [];
        $raw = isset($api['data']['accounts']) && is_array($api['data']['accounts']) ? $api['data']['accounts'] : [];
        foreach ($raw as $a) {
            $list[] = [
                'id' => isset($a['id']) ? (string)$a['id'] : '',
                'name' => isset($a['name']) ? (string)$a['name'] : '',
                'type' => isset($a['type']) ? (string)$a['type'] : '',
                'status' => isset($a['status']) ? (string)$a['status'] : '',
            ];
        }

        return ['ok' => true, 'accounts' => $list, 'error' => ''];
    }

    public static function accountsHint(string $token): string
    {
        $r = self::listAccounts($token);
        if (!$r['ok']) {
            return ' (GetAccounts: ' . $r['error'] . ')';
        }
        if ($r['accounts'] === []) {
            return ' (no accounts for token — check scopes / sandbox)';
        }
        $parts = [];
        foreach ($r['accounts'] as $a) {
            $parts[] = $a['id']
                . ($a['name'] !== '' ? ' «' . $a['name'] . '»' : '')
                . ($a['type'] !== '' ? ' [' . $a['type'] . ']' : '');
        }

        return ' accountId: ' . implode('; ', $parts);
    }

    /**
     * @param array<string,mixed> $data
     * @return array{total:float,positions:list<array<string,mixed>>}
     */
    public static function parsePortfolio(array $data): array
    {
        $total = 0.0;
        if (isset($data['totalAmountPortfolio'])) {
            $total = self::moneyToFloat($data['totalAmountPortfolio']);
        } elseif (isset($data['totalAmountShares'])) {
            foreach (['totalAmountShares', 'totalAmountBonds', 'totalAmountEtf', 'totalAmountCurrencies', 'totalAmountFutures', 'totalAmountOptions', 'totalAmountSp'] as $k) {
                if (isset($data[$k])) {
                    $total += self::moneyToFloat($data[$k]);
                }
            }
        }
        $positions = [];
        $list = isset($data['positions']) && is_array($data['positions']) ? $data['positions'] : [];
        foreach ($list as $p) {
            $qty = isset($p['quantity']) ? self::quotationToFloat($p['quantity']) : 0.0;
            $price = isset($p['currentPrice']) ? self::moneyToFloat($p['currentPrice']) : 0.0;
            $avg = isset($p['averagePositionPrice']) ? self::moneyToFloat($p['averagePositionPrice']) : 0.0;
            $value = $qty * $price;
            if (isset($p['currentNkd'])) {
                $value += self::moneyToFloat($p['currentNkd']) * abs($qty);
            }
            $meta = [];
            if (isset($p['expectedYield'])) {
                $meta['expectedYield'] = self::quotationToFloat($p['expectedYield']);
            }
            $ticker = isset($p['ticker']) ? (string)$p['ticker'] : '';
            $positions[] = [
                'ticker' => $ticker,
                'figi' => isset($p['figi']) ? (string)$p['figi'] : '',
                'name' => $ticker,
                'asset_class' => self::mapInstrumentType(isset($p['instrumentType']) ? (string)$p['instrumentType'] : ''),
                'qty' => $qty,
                'price' => $price,
                'value' => $value,
                'avg_price' => $avg,
                'meta' => $meta,
            ];
        }

        return ['total' => $total, 'positions' => $positions];
    }

    /** @return array{name:string,ticker:string,instrument_type:string} */
    public static function instrumentByFigi(string $token, string $figi): array
    {
        $figi = trim($figi);
        if ($figi === '') {
            return ['name' => '', 'ticker' => '', 'instrument_type' => ''];
        }
        if (isset(self::$instrumentCache[$figi])) {
            return self::$instrumentCache[$figi];
        }
        $api = self::post(
            'tinkoff.public.invest.api.contract.v1.InstrumentsService/GetInstrumentBy',
            $token,
            ['idType' => 'INSTRUMENT_ID_TYPE_FIGI', 'classCode' => '', 'id' => $figi]
        );
        $out = ['name' => '', 'ticker' => '', 'instrument_type' => ''];
        if ($api['ok'] && isset($api['data']['instrument']) && is_array($api['data']['instrument'])) {
            $ins = $api['data']['instrument'];
            $out['name'] = isset($ins['name']) ? (string)$ins['name'] : '';
            $out['ticker'] = isset($ins['ticker']) ? (string)$ins['ticker'] : '';
            if (isset($ins['instrumentType'])) {
                $out['instrument_type'] = (string)$ins['instrumentType'];
            } elseif (isset($ins['instrument_type'])) {
                $out['instrument_type'] = (string)$ins['instrument_type'];
            } elseif (isset($ins['instrumentKind'])) {
                $out['instrument_type'] = (string)$ins['instrumentKind'];
            }
        }
        self::$instrumentCache[$figi] = $out;

        return $out;
    }

    /** @param list<array<string,mixed>> $positions */
    public static function enrichPositionNames(string $token, array &$positions): void
    {
        foreach ($positions as $i => $pos) {
            $figi = isset($pos['figi']) ? (string)$pos['figi'] : '';
            if ($figi === '') {
                continue;
            }
            $ins = self::instrumentByFigi($token, $figi);
            if ($ins['name'] !== '') {
                $positions[$i]['name'] = $ins['name'];
            }
            if ($ins['ticker'] !== '' && (empty($pos['ticker']) || $pos['ticker'] === $pos['name'])) {
                $positions[$i]['ticker'] = $ins['ticker'];
            }
        }
        self::enrichBondNotes($token, $positions);
    }

    /** @param mixed $ts */
    public static function tsToDate($ts): string
    {
        if (is_string($ts) && preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $ts, $m)) {
            return $m[3] . '.' . $m[2] . '.' . $m[1];
        }
        if (is_array($ts) && isset($ts['seconds'])) {
            return date('d.m.Y', (int)$ts['seconds']);
        }

        return '';
    }

    public static function bondNoteByFigi(string $token, string $figi): string
    {
        $figi = trim($figi);
        if ($figi === '') {
            return '';
        }
        if (isset(self::$bondNoteCache[$figi])) {
            return self::$bondNoteCache[$figi];
        }
        $parts = [];
        $api = self::post(
            'tinkoff.public.invest.api.contract.v1.InstrumentsService/BondBy',
            $token,
            ['idType' => 'INSTRUMENT_ID_TYPE_FIGI', 'classCode' => '', 'id' => $figi]
        );
        if ($api['ok'] && isset($api['data']['instrument']) && is_array($api['data']['instrument'])) {
            $b = $api['data']['instrument'];
            $settleCur = '';
            if (!empty($b['currency'])) {
                $settleCur = strtoupper(trim((string)$b['currency']));
            }
            if ($settleCur === 'RUR') {
                $settleCur = 'RUB';
            }
            $nomCur = '';
            if (isset($b['nominal']) && is_array($b['nominal']) && !empty($b['nominal']['currency'])) {
                $nomCur = strtoupper(trim((string)$b['nominal']['currency']));
            } elseif (isset($b['initialNominal']) && is_array($b['initialNominal']) && !empty($b['initialNominal']['currency'])) {
                $nomCur = strtoupper(trim((string)$b['initialNominal']['currency']));
            } elseif (isset($b['initial_nominal']) && is_array($b['initial_nominal']) && !empty($b['initial_nominal']['currency'])) {
                $nomCur = strtoupper(trim((string)$b['initial_nominal']['currency']));
            }
            if ($nomCur === 'RUR') {
                $nomCur = 'RUB';
            }
            if ($nomCur === '') {
                $assetUid = isset($b['assetUid']) ? $b['assetUid'] : (isset($b['asset_uid']) ? $b['asset_uid'] : '');
                if ($assetUid !== '') {
                    $assetApi = self::post(
                        'tinkoff.public.invest.api.contract.v1.InstrumentsService/GetAssetBy',
                        $token,
                        ['id' => $assetUid]
                    );
                    if ($assetApi['ok'] && isset($assetApi['data']['asset']['security']['bond']['nominalCurrency'])) {
                        $nomCur = strtoupper(trim((string)$assetApi['data']['asset']['security']['bond']['nominalCurrency']));
                    } elseif ($assetApi['ok'] && isset($assetApi['data']['asset']['security']['bond']['nominal_currency'])) {
                        $nomCur = strtoupper(trim((string)$assetApi['data']['asset']['security']['bond']['nominal_currency']));
                    }
                    if ($nomCur === 'RUR') {
                        $nomCur = 'RUB';
                    }
                }
            }
            $bondType = isset($b['bondType']) ? $b['bondType'] : (isset($b['bond_type']) ? $b['bond_type'] : '');
            $isReplaced = (stripos((string)$bondType, 'REPLACED') !== false);
            if ($nomCur !== '') {
                if ($settleCur !== '' && $settleCur !== $nomCur) {
                    $parts[] = 'ном.' . $nomCur . '/расч.' . $settleCur;
                } else {
                    $parts[] = 'ном.' . $nomCur;
                }
            } elseif ($settleCur !== '') {
                $parts[] = 'расч.' . $settleCur;
            }
            if ($isReplaced) {
                $parts[] = 'ЗО';
            }
            $mat = '';
            if (!empty($b['maturityDate'])) {
                $mat = self::tsToDate($b['maturityDate']);
            } elseif (!empty($b['maturity_date'])) {
                $mat = self::tsToDate($b['maturity_date']);
            }
            if ($mat !== '') {
                $parts[] = 'погаш. ' . $mat;
            }
            $call = '';
            if (!empty($b['callDate'])) {
                $call = self::tsToDate($b['callDate']);
            } elseif (!empty($b['call_date'])) {
                $call = self::tsToDate($b['call_date']);
            }
            if (!empty($b['amortizationFlag']) || !empty($b['amortization_flag'])) {
                $parts[] = 'аморт.';
            }
            if (!empty($b['floatingCouponFlag']) || !empty($b['floating_coupon_flag'])) {
                $parts[] = 'плав.куп.';
            }
            $risk = isset($b['riskLevel']) ? $b['riskLevel'] : (isset($b['risk_level']) ? $b['risk_level'] : '');
            $riskMap = [
                'RISK_LEVEL_LOW' => 'риск низк.',
                'RISK_LEVEL_MODERATE' => 'риск ср.',
                'RISK_LEVEL_HIGH' => 'риск выс.',
                '1' => 'риск низк.',
                '2' => 'риск ср.',
                '3' => 'риск выс.',
            ];
            $rk = strtoupper((string)$risk);
            if (isset($riskMap[$rk])) {
                $parts[] = $riskMap[$rk];
            } elseif (isset($riskMap[(string)$risk])) {
                $parts[] = $riskMap[(string)$risk];
            }
            $from = gmdate('Y-m-d\TH:i:s\Z', time() - 86400);
            $to = gmdate('Y-m-d\TH:i:s\Z', time() + 86400 * 365 * 8);
            $ev = self::post(
                'tinkoff.public.invest.api.contract.v1.InstrumentsService/GetBondEvents',
                $token,
                [
                    'from' => $from,
                    'to' => $to,
                    'instrumentId' => $figi,
                    'type' => 'EVENT_TYPE_CALL',
                ]
            );
            $offerDate = '';
            if ($ev['ok'] && isset($ev['data']['events']) && is_array($ev['data']['events'])) {
                $bestTs = 0;
                foreach ($ev['data']['events'] as $e) {
                    $raw = isset($e['eventDate']) ? $e['eventDate'] : (isset($e['payDate']) ? $e['payDate'] : null);
                    $d = self::tsToDate($raw);
                    if ($d === '') {
                        continue;
                    }
                    $ts = 0;
                    if (is_array($raw) && isset($raw['seconds'])) {
                        $ts = (int)$raw['seconds'];
                    } elseif (is_string($raw)) {
                        $ts = (int)strtotime($raw);
                    }
                    if ($ts >= time() - 86400 && ($bestTs === 0 || $ts < $bestTs)) {
                        $bestTs = $ts;
                        $offerDate = $d;
                    }
                }
            }
            if ($offerDate === '' && $call !== '') {
                $offerDate = $call;
            }
            if ($offerDate !== '') {
                $parts[] = 'оферта ' . $offerDate;
            }
        }
        $note = implode(' · ', $parts);
        self::$bondNoteCache[$figi] = $note;

        return $note;
    }

    /** @param list<array<string,mixed>> $positions */
    public static function enrichBondNotes(string $token, array &$positions): void
    {
        foreach ($positions as $i => $pos) {
            if (($pos['asset_class'] ?? '') !== 'bond') {
                continue;
            }
            $figi = isset($pos['figi']) ? (string)$pos['figi'] : '';
            if ($figi === '') {
                continue;
            }
            $note = self::bondNoteByFigi($token, $figi);
            if ($note !== '') {
                if (!isset($positions[$i]['meta']) || !is_array($positions[$i]['meta'])) {
                    $positions[$i]['meta'] = [];
                }
                $positions[$i]['meta']['bond_note'] = $note;
            }
        }
    }

    public static function classSortKey(string $class): int
    {
        $map = ['bond' => 1, 'stock' => 2, 'share' => 2, 'etf' => 3, 'currency' => 4, 'money' => 5, 'other' => 9];
        $c = strtolower($class);

        return $map[$c] ?? 8;
    }

    /** @param list<array<string,mixed>> $rows */
    public static function sortPositionRows(array &$rows): void
    {
        usort($rows, static function (array $a, array $b): int {
            $ca = (string)($a['ASSET_CLASS'] ?? $a['asset_class'] ?? '');
            $cb = (string)($b['ASSET_CLASS'] ?? $b['asset_class'] ?? '');
            $ka = self::classSortKey($ca);
            $kb = self::classSortKey($cb);
            if ($ka !== $kb) {
                return ($ka < $kb) ? -1 : 1;
            }
            $ta = (string)($a['TICKER'] ?? $a['ticker'] ?? '');
            $tb = (string)($b['TICKER'] ?? $b['ticker'] ?? '');

            return strcasecmp($ta, $tb);
        });
    }

    /**
     * @param list<list<array<string,mixed>>> $lists
     * @return list<array<string,mixed>>
     */
    public static function mergePositions(array $lists): array
    {
        $by = [];
        foreach ($lists as $positions) {
            foreach ($positions as $p) {
                $key = $p['figi'] !== '' ? 'f:' . $p['figi'] : 't:' . $p['ticker'];
                if (!isset($by[$key])) {
                    $by[$key] = $p;
                    continue;
                }
                $by[$key]['qty'] += $p['qty'];
                $by[$key]['value'] += $p['value'];
                if ($p['name'] !== '' && ($by[$key]['name'] === '' || $by[$key]['name'] === $by[$key]['ticker'])) {
                    $by[$key]['name'] = $p['name'];
                }
                if (!empty($p['meta']['bond_note']) && empty($by[$key]['meta']['bond_note'])) {
                    if (!isset($by[$key]['meta']) || !is_array($by[$key]['meta'])) {
                        $by[$key]['meta'] = [];
                    }
                    $by[$key]['meta']['bond_note'] = $p['meta']['bond_note'];
                }
                $q = $by[$key]['qty'];
                if (abs($q) > 1e-9) {
                    $by[$key]['price'] = $by[$key]['value'] / $q;
                }
            }
        }

        return array_values($by);
    }

    /**
     * Operations summary for period across accounts.
     *
     * @param list<string> $accountIds
     * @return array<string,mixed>
     */
    public static function fetchOperationsSummary(string $token, array $accountIds, int $fromTs, int $toTs): array
    {
        $from = gmdate('Y-m-d\TH:i:s\Z', $fromTs);
        $to = gmdate('Y-m-d\TH:i:s\Z', $toTs);
        $summary = [
            'in' => 0.0, 'out' => 0.0,
            'buy' => 0, 'sell' => 0, 'repay' => 0,
            'buy_sum' => 0.0, 'sell_sum' => 0.0, 'repay_sum' => 0.0,
            'trades' => [],
            'moves' => [],
            'ok' => true, 'error' => '',
        ];
        $byFigi = [];
        foreach ($accountIds as $accountId) {
            $accountId = trim((string)$accountId);
            if ($accountId === '') {
                continue;
            }
            $api = self::post(
                'tinkoff.public.invest.api.contract.v1.OperationsService/GetOperations',
                $token,
                [
                    'accountId' => $accountId,
                    'from' => $from,
                    'to' => $to,
                    'state' => 'OPERATION_STATE_EXECUTED',
                ]
            );
            if (!$api['ok']) {
                $summary['ok'] = false;
                $summary['error'] = $api['error'];
                continue;
            }
            if (!isset($api['data']['operations']) || !is_array($api['data']['operations'])) {
                continue;
            }
            foreach ($api['data']['operations'] as $op) {
                $type = isset($op['operationType']) ? $op['operationType'] : (isset($op['type']) ? $op['type'] : '');
                $pay = isset($op['payment']) ? self::moneyToFloat($op['payment']) : 0.0;
                $t = strtoupper((string)$type);
                $side = '';
                if (strpos($t, 'INPUT') !== false || strpos($t, 'REPLENISH') !== false || strpos($t, 'DEPOSIT') !== false) {
                    $summary['in'] += abs($pay);
                    continue;
                }
                if (strpos($t, 'OUTPUT') !== false || strpos($t, 'WITHDRAW') !== false) {
                    $summary['out'] += abs($pay);
                    continue;
                }
                if (strpos($t, 'BUY') !== false) {
                    $side = 'buy';
                    $summary['buy']++;
                    $summary['buy_sum'] += abs($pay);
                } elseif (strpos($t, 'SELL') !== false) {
                    $side = 'sell';
                    $summary['sell']++;
                    $summary['sell_sum'] += abs($pay);
                } elseif (strpos($t, 'REPAYMENT') !== false || strpos($t, 'REDEEM') !== false || strpos($t, 'BOND_REPAY') !== false) {
                    $side = 'repay';
                    $summary['repay']++;
                    $summary['repay_sum'] += abs($pay);
                } else {
                    continue;
                }
                $figi = isset($op['figi']) ? trim((string)$op['figi']) : '';
                $qty = isset($op['quantity']) ? abs((float)$op['quantity']) : 0.0;
                $cur = '';
                if (isset($op['payment']['currency'])) {
                    $cur = strtoupper((string)$op['payment']['currency']);
                } elseif (isset($op['currency'])) {
                    $cur = strtoupper((string)$op['currency']);
                }
                $opClass = '';
                if (isset($op['instrumentType'])) {
                    $opClass = self::mapInstrumentType((string)$op['instrumentType']);
                }
                $key = $figi !== '' ? $figi : ('nof_' . $side . '_' . $cur);
                if (!isset($byFigi[$key])) {
                    $ticker = '';
                    $name = '';
                    $insType = $opClass;
                    if ($figi !== '') {
                        $ins = self::instrumentByFigi($token, $figi);
                        $ticker = $ins['ticker'];
                        $name = $ins['name'];
                        if (($insType === '' || $insType === 'other') && $ins['instrument_type'] !== '') {
                            $insType = self::mapInstrumentType($ins['instrument_type']);
                        }
                    }
                    $byFigi[$key] = [
                        'figi' => $figi,
                        'ticker' => $ticker,
                        'name' => $name,
                        'asset_class' => $insType !== '' ? $insType : 'other',
                        'buy_qty' => 0.0,
                        'sell_qty' => 0.0,
                        'buy_n' => 0,
                        'sell_n' => 0,
                        'buy_sum' => 0.0,
                        'sell_sum' => 0.0,
                        'currency' => $cur,
                    ];
                }
                if ($side === 'buy') {
                    $byFigi[$key]['buy_qty'] += $qty;
                    $byFigi[$key]['buy_sum'] += abs($pay);
                    $byFigi[$key]['buy_n']++;
                } else {
                    $byFigi[$key]['sell_qty'] += $qty;
                    $byFigi[$key]['sell_sum'] += abs($pay);
                    $byFigi[$key]['sell_n']++;
                }
                if ($byFigi[$key]['currency'] === '' && $cur !== '') {
                    $byFigi[$key]['currency'] = $cur;
                }
                if (($byFigi[$key]['asset_class'] === '' || $byFigi[$key]['asset_class'] === 'other')
                    && $opClass !== '' && $opClass !== 'other') {
                    $byFigi[$key]['asset_class'] = $opClass;
                }
            }
        }
        $moves = [];
        $tradesCompat = [];
        foreach ($byFigi as $row) {
            $row['buy_qty'] = round($row['buy_qty'], 6);
            $row['sell_qty'] = round($row['sell_qty'], 6);
            $row['buy_sum'] = round($row['buy_sum'], 2);
            $row['sell_sum'] = round($row['sell_sum'], 2);
            $row['delta_qty'] = round($row['buy_qty'] - $row['sell_qty'], 6);
            $row['net'] = round($row['buy_sum'] - $row['sell_sum'], 2);
            $moves[] = $row;
            if ($row['buy_n'] > 0) {
                $tradesCompat[] = [
                    'side' => 'buy', 'figi' => $row['figi'], 'ticker' => $row['ticker'], 'name' => $row['name'],
                    'qty' => $row['buy_qty'], 'amount' => $row['buy_sum'], 'currency' => $row['currency'], 'n' => $row['buy_n'],
                ];
            }
            if ($row['sell_n'] > 0) {
                $tradesCompat[] = [
                    'side' => 'sell', 'figi' => $row['figi'], 'ticker' => $row['ticker'], 'name' => $row['name'],
                    'qty' => $row['sell_qty'], 'amount' => $row['sell_sum'], 'currency' => $row['currency'], 'n' => $row['sell_n'],
                ];
            }
        }
        usort($moves, static function (array $a, array $b): int {
            $sa = ($a['delta_qty'] > 0) ? 0 : (($a['delta_qty'] < 0) ? 1 : 2);
            $sb = ($b['delta_qty'] > 0) ? 0 : (($b['delta_qty'] < 0) ? 1 : 2);
            if ($sa !== $sb) {
                return ($sa < $sb) ? -1 : 1;
            }
            $na = abs($a['net']);
            $nb = abs($b['net']);
            if ($na == $nb) {
                return 0;
            }

            return ($na > $nb) ? -1 : 1;
        });
        $summary['moves'] = $moves;
        $summary['trades'] = $tradesCompat;
        $summary['buy_sum'] = round($summary['buy_sum'], 2);
        $summary['sell_sum'] = round($summary['sell_sum'], 2);
        $summary['repay_sum'] = round($summary['repay_sum'], 2);
        $summary['in'] = round($summary['in'], 2);
        $summary['out'] = round($summary['out'], 2);

        return $summary;
    }

    /**
     * @param array<string,mixed>|null $opsSummary
     * @return list<array<string,mixed>>
     */
    public static function movesFromOpsSummary(?array $opsSummary): array
    {
        if (!is_array($opsSummary)) {
            return [];
        }
        if (!empty($opsSummary['moves']) && is_array($opsSummary['moves'])) {
            return $opsSummary['moves'];
        }
        $trades = isset($opsSummary['trades']) && is_array($opsSummary['trades']) ? $opsSummary['trades'] : [];
        if ($trades === []) {
            return [];
        }
        $by = [];
        foreach ($trades as $tr) {
            $figi = isset($tr['figi']) ? (string)$tr['figi'] : '';
            $key = $figi !== '' ? $figi : ('t_' . (isset($tr['ticker']) ? $tr['ticker'] : uniqid('', true)));
            if (!isset($by[$key])) {
                $by[$key] = [
                    'figi' => $figi,
                    'ticker' => isset($tr['ticker']) ? $tr['ticker'] : '',
                    'name' => isset($tr['name']) ? $tr['name'] : '',
                    'asset_class' => isset($tr['asset_class']) ? $tr['asset_class'] : 'other',
                    'buy_qty' => 0.0, 'sell_qty' => 0.0,
                    'buy_n' => 0, 'sell_n' => 0,
                    'buy_sum' => 0.0, 'sell_sum' => 0.0,
                    'currency' => isset($tr['currency']) ? $tr['currency'] : '',
                ];
            }
            $side = isset($tr['side']) ? $tr['side'] : '';
            $qty = isset($tr['qty']) ? (float)$tr['qty'] : 0.0;
            $amt = isset($tr['amount']) ? (float)$tr['amount'] : 0.0;
            $n = isset($tr['n']) ? (int)$tr['n'] : 1;
            if ($side === 'buy') {
                $by[$key]['buy_qty'] += $qty;
                $by[$key]['buy_sum'] += $amt;
                $by[$key]['buy_n'] += $n;
            } else {
                $by[$key]['sell_qty'] += $qty;
                $by[$key]['sell_sum'] += $amt;
                $by[$key]['sell_n'] += $n;
            }
        }
        $moves = [];
        foreach ($by as $row) {
            $row['delta_qty'] = round($row['buy_qty'] - $row['sell_qty'], 6);
            $row['net'] = round($row['buy_sum'] - $row['sell_sum'], 2);
            $moves[] = $row;
        }

        return $moves;
    }
}
