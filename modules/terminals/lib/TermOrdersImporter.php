<?php
declare(strict_types=1);

/**
 * Safe importer for volgatermy vtorders feed.
 * Legacy oper executed remote SQL via mysql_query — we only accept INSERT into base_term_orders
 * or a JSON array of order objects.
 */
final class TermOrdersImporter
{
    /**
     * @return array{ok:bool,imported:int,error?:string,last_id?:int}
     */
    public static function run(): array
    {
        if (!Database::tableExists('base_term_orders')) {
            return ['ok' => false, 'imported' => 0, 'error' => 'base_term_orders missing'];
        }
        $base = TerminalsConfig::ordersUrl();
        $lastId = TermOrdersRepository::lastId();
        $url = $base;
        if ($lastId > 0) {
            $url .= (strpos($base, '?') === false ? '?' : '&') . 'start=' . $lastId;
        }
        $raw = self::fetch($url);
        if ($raw === null) {
            return ['ok' => false, 'imported' => 0, 'error' => 'fetch failed'];
        }
        $raw = trim($raw);
        if ($raw === '' || strtolower($raw) === 'timeout') {
            return ['ok' => true, 'imported' => 0, 'last_id' => $lastId];
        }

        $rows = self::parsePayload($raw);
        if ($rows === null) {
            return ['ok' => false, 'imported' => 0, 'error' => 'unsupported payload (refuse raw SQL exec)'];
        }
        $n = 0;
        foreach ($rows as $row) {
            TermOrdersRepository::insertRow($row);
            $n++;
        }
        return ['ok' => true, 'imported' => $n, 'last_id' => TermOrdersRepository::lastId()];
    }

    private static function fetch(string $url): ?string
    {
        if (!function_exists('curl_init')) {
            return null;
        }
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $raw = curl_exec($ch);
        $ok = $raw !== false && curl_errno($ch) === 0;
        curl_close($ch);
        return $ok ? (string)$raw : null;
    }

    /**
     * @return list<array<string,mixed>>|null
     */
    private static function parsePayload(string $raw): ?array
    {
        $json = json_decode($raw, true);
        if (is_array($json)) {
            $list = isset($json[0]) ? $json : (isset($json['orders']) && is_array($json['orders']) ? $json['orders'] : null);
            if ($list === null) {
                return null;
            }
            $out = [];
            foreach ($list as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $out[] = self::mapKeys($item);
            }
            return $out;
        }

        // Only allow INSERT INTO base_term_orders … ; (possibly multiple)
        if (!preg_match('/^\s*INSERT\s+INTO\s+`?base_term_orders`?\s+/is', $raw)) {
            return null;
        }
        if (preg_match('/\b(DROP|DELETE|UPDATE|ALTER|TRUNCATE|CREATE|REPLACE\s+INTO\s+(?!`?base_term_orders))\b/i', $raw)) {
            return null;
        }

        $out = [];
        if (preg_match_all(
            '/INSERT\s+INTO\s+`?base_term_orders`?\s*\(([^)]+)\)\s*VALUES\s*\(([^;]+)\)/is',
            $raw,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $m) {
                $cols = self::splitSqlList($m[1]);
                $vals = self::splitSqlList($m[2]);
                if (count($cols) !== count($vals) || $cols === []) {
                    continue;
                }
                $assoc = [];
                foreach ($cols as $i => $col) {
                    $assoc[strtoupper(trim($col, " \t\n\r\0\x0B`"))] = self::unquoteSql($vals[$i]);
                }
                $out[] = self::mapKeys($assoc);
            }
        }
        return $out !== [] ? $out : null;
    }

    /**
     * @param array<string,mixed> $item
     * @return array<string,mixed>
     */
    private static function mapKeys(array $item): array
    {
        $get = static function (array $a, array $keys, $default = 0) {
            foreach ($keys as $k) {
                if (array_key_exists($k, $a)) {
                    return $a[$k];
                }
                $uk = strtoupper($k);
                if (array_key_exists($uk, $a)) {
                    return $a[$uk];
                }
                $lk = strtolower($k);
                if (array_key_exists($lk, $a)) {
                    return $a[$lk];
                }
            }
            return $default;
        };
        return [
            'id' => (int)$get($item, ['ID', 'id'], 0),
            'dt_create' => (int)$get($item, ['DT_CREATE', 'dt_create'], 0),
            'dt_visit' => (int)$get($item, ['DT_VISIT', 'dt_visit'], 0),
            'days_before' => (int)$get($item, ['DAYS_BEFORE', 'days_before'], 0),
            'hour_visit' => (int)$get($item, ['HOUR_VISIT', 'hour_visit'], 0),
            'phone' => (string)$get($item, ['PHONE', 'phone'], ''),
            'adults' => (int)$get($item, ['ADULTS', 'adults'], 0),
            'children' => (int)$get($item, ['CHILDREN', 'children'], 0),
            'elders' => (int)$get($item, ['ELDERS', 'elders'], 0),
            'total' => (int)$get($item, ['TOTAL', 'total'], 0),
            'sert_summ' => (int)$get($item, ['SERT_SUMM', 'sert_summ'], 0),
            'total_summ' => (int)$get($item, ['TOTAL_SUMM', 'total_summ'], 0),
        ];
    }

    /** @return list<string> */
    private static function splitSqlList(string $s): array
    {
        $out = [];
        $cur = '';
        $in = false;
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $ch = $s[$i];
            if ($ch === "'" && ($i === 0 || $s[$i - 1] !== '\\')) {
                $in = !$in;
                $cur .= $ch;
                continue;
            }
            if ($ch === ',' && !$in) {
                $out[] = trim($cur);
                $cur = '';
                continue;
            }
            $cur .= $ch;
        }
        if (trim($cur) !== '') {
            $out[] = trim($cur);
        }
        return $out;
    }

    /** @return mixed */
    private static function unquoteSql(string $v)
    {
        $v = trim($v);
        if (strcasecmp($v, 'NULL') === 0) {
            return null;
        }
        if (strlen($v) >= 2 && $v[0] === "'" && substr($v, -1) === "'") {
            return stripcslashes(substr($v, 1, -1));
        }
        return $v;
    }
}
