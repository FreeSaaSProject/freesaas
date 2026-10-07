<?php
declare(strict_types=1);

/**
 * Summary computed metrics: safe formula tokenizer/eval + percent/check/result kinds.
 * Port of oper records_summary_computed.inc.php (no HTML editor).
 */
final class SummaryComputed
{
    /** @return list<string> */
    public static function extractRefs(string $expr): array
    {
        $refs = [];
        if ($expr === '') {
            return $refs;
        }
        if (preg_match_all('/\{([A-Za-z_][A-Za-z0-9_.]*)\}/', $expr, $m)) {
            foreach ($m[1] as $r) {
                $refs[$r] = true;
            }
        }
        return array_keys($refs);
    }

    /** @return list<string> */
    public static function extractCalcDeps(string $expr): array
    {
        $deps = [];
        foreach (self::extractRefs($expr) as $r) {
            if (strpos($r, 'calc.') === 0) {
                $deps[] = substr($r, 5);
            }
        }
        return $deps;
    }

    /**
     * @return list<array<string,mixed>>|false
     */
    public static function tokenize(string $expr)
    {
        $expr = trim($expr);
        if ($expr === '') {
            return false;
        }
        if (strlen($expr) > 2000) {
            return false;
        }
        $tokens = [];
        $i = 0;
        $n = strlen($expr);
        while ($i < $n) {
            $ch = $expr[$i];
            if ($ch === ' ' || $ch === "\t" || $ch === "\n" || $ch === "\r") {
                $i++;
                continue;
            }
            if ($ch === '{') {
                $j = strpos($expr, '}', $i);
                if ($j === false) {
                    return false;
                }
                $ref = substr($expr, $i + 1, $j - $i - 1);
                if (!preg_match('/^[A-Za-z_][A-Za-z0-9_.]*$/', $ref)) {
                    return false;
                }
                $tokens[] = ['t' => 'ref', 'v' => $ref];
                $i = $j + 1;
                continue;
            }
            if (($ch >= '0' && $ch <= '9') || ($ch === '.' && $i + 1 < $n && $expr[$i + 1] >= '0' && $expr[$i + 1] <= '9')) {
                $j = $i;
                while ($j < $n && (($expr[$j] >= '0' && $expr[$j] <= '9') || $expr[$j] === '.')) {
                    $j++;
                }
                $num = substr($expr, $i, $j - $i);
                if (!is_numeric($num)) {
                    return false;
                }
                $tokens[] = ['t' => 'num', 'v' => (float)$num];
                $i = $j;
                continue;
            }
            if (($ch >= 'A' && $ch <= 'Z') || ($ch >= 'a' && $ch <= 'z') || $ch === '_') {
                $j = $i + 1;
                while ($j < $n && (
                    ($expr[$j] >= 'A' && $expr[$j] <= 'Z')
                    || ($expr[$j] >= 'a' && $expr[$j] <= 'z')
                    || ($expr[$j] >= '0' && $expr[$j] <= '9')
                    || $expr[$j] === '_'
                )) {
                    $j++;
                }
                $name = substr($expr, $i, $j - $i);
                $tokens[] = ['t' => 'id', 'v' => strtoupper($name)];
                $i = $j;
                continue;
            }
            $two = ($i + 1 < $n) ? substr($expr, $i, 2) : '';
            if ($two === '>=' || $two === '<=' || $two === '<>') {
                $tokens[] = ['t' => 'cmp', 'v' => $two];
                $i += 2;
                continue;
            }
            if ($ch === '=' || $ch === '<' || $ch === '>') {
                $tokens[] = ['t' => 'cmp', 'v' => $ch];
                $i++;
                continue;
            }
            if ($ch === '+' || $ch === '-' || $ch === '*' || $ch === '/') {
                $tokens[] = ['t' => 'op', 'v' => $ch];
                $i++;
                continue;
            }
            if ($ch === '(') {
                $tokens[] = ['t' => 'lp'];
                $i++;
                continue;
            }
            if ($ch === ')') {
                $tokens[] = ['t' => 'rp'];
                $i++;
                continue;
            }
            if ($ch === ',') {
                $tokens[] = ['t' => 'comma'];
                $i++;
                continue;
            }
            return false;
        }
        return $tokens;
    }

    /**
     * @param array<string,float|int|null> $vars
     * @return float|null
     */
    public static function evalFormula(string $expr, array $vars): ?float
    {
        $tokens = self::tokenize($expr);
        if ($tokens === false || $tokens === []) {
            return null;
        }
        $pos = 0;
        $nullFlag = false;
        $val = self::parseCmp($tokens, $pos, $vars, $nullFlag);
        if ($nullFlag) {
            return null;
        }
        if ($pos !== count($tokens)) {
            return null;
        }
        if ($val === null) {
            return null;
        }
        if (!is_numeric($val) || is_nan((float)$val) || is_infinite((float)$val)) {
            return null;
        }
        return (float)$val;
    }

    public static function normalizeOperandRef(string $ref): string
    {
        $ref = trim($ref);
        if ($ref === '') {
            return '';
        }
        if (strlen($ref) >= 2 && $ref[0] === '{' && substr($ref, -1) === '}') {
            $ref = substr($ref, 1, -1);
        }
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_.]*$/', $ref)) {
            return '';
        }
        return $ref;
    }

    /**
     * @param mixed $src
     * @return array<string,mixed>
     */
    public static function normalizeMetric($src, int $idx = 0): array
    {
        if (!is_array($src)) {
            $src = [];
        }
        $key = isset($src['key']) ? preg_replace('/[^A-Za-z0-9_]/', '', (string)$src['key']) : '';
        if ($key === '') {
            $key = 'calc_' . $idx;
        }
        $title = isset($src['title']) ? trim((string)$src['title']) : '';
        $kind = isset($src['kind']) ? strtolower(trim((string)$src['kind'])) : 'percent';
        if (!in_array($kind, ['percent', 'check', 'result'], true)) {
            $kind = 'percent';
        }
        $checkBase = isset($src['check_base']) ? strtolower(trim((string)$src['check_base'])) : 'percent';
        if (!in_array($checkBase, ['percent', 'value'], true)) {
            $checkBase = 'percent';
        }
        $a = self::normalizeOperandRef(isset($src['a']) ? (string)$src['a'] : '');
        $b = self::normalizeOperandRef(isset($src['b']) ? (string)$src['b'] : '');
        $formula = isset($src['formula']) ? trim((string)$src['formula']) : '';
        $formula = mb_substr($formula, 0, 2000, 'UTF-8');
        $unit = isset($src['unit']) ? trim((string)$src['unit']) : '';
        $unit = mb_substr($unit, 0, 24, 'UTF-8');
        $decimals = isset($src['decimals']) ? (int)$src['decimals'] : (($kind === 'percent') ? 1 : 0);
        if ($decimals < 0) {
            $decimals = 0;
        }
        if ($decimals > 4) {
            $decimals = 4;
        }
        $tip = isset($src['tip']) ? trim((string)$src['tip']) : '';
        $tip = mb_substr($tip, 0, 400, 'UTF-8');
        $compare = isset($src['compare']) ? strtolower(trim((string)$src['compare'])) : 'gte';
        if (!in_array($compare, ['gte', 'lte'], true)) {
            $compare = 'gte';
        }
        $hasTarget = 0;
        $target = 0.0;
        if (array_key_exists('target', $src) && trim((string)$src['target']) !== '') {
            $hasTarget = 1;
            $target = (float)$src['target'];
        } elseif (!empty($src['has_target'])) {
            $hasTarget = 1;
            $target = isset($src['target']) ? (float)$src['target'] : 0.0;
        }
        if ($kind === 'check') {
            $hasTarget = 1;
        }
        if ($kind === 'percent' && $unit === '') {
            $unit = '%';
        }
        $out = [
            'key' => $key,
            'title' => ($title !== '' ? $title : ('Metric #' . ($idx + 1))),
            'kind' => $kind,
            'check_base' => $checkBase,
            'a' => $a,
            'b' => $b,
            'formula' => $formula,
            'unit' => $unit,
            'decimals' => $decimals,
            'tip' => $tip,
            'has_target' => $hasTarget,
            'target' => $target,
            'compare' => $compare,
            'show_card' => array_key_exists('show_card', $src) ? (!empty($src['show_card']) ? 1 : 0) : 0,
            'show_detail' => array_key_exists('show_detail', $src) ? (!empty($src['show_detail']) ? 1 : 0) : 1,
            '_error' => '',
        ];
        $valid = false;
        if ($kind === 'percent') {
            $valid = ($a !== '' && $b !== '');
        } elseif ($kind === 'check') {
            $valid = ($a !== '' && ($checkBase === 'value' || $b !== ''));
        } elseif ($kind === 'result') {
            $valid = ($formula !== '' && self::tokenize($formula) !== false);
            if ($formula !== '' && self::tokenize($formula) === false) {
                $valid = false;
            }
        }
        $out['_valid'] = $valid ? 1 : 0;
        return $out;
    }

    /**
     * @param mixed $list
     * @return list<array<string,mixed>>
     */
    public static function normalizeMetrics($list): array
    {
        if (!is_array($list)) {
            return [];
        }
        $raw = [];
        $i = 0;
        foreach ($list as $src) {
            $n = self::normalizeMetric($src, $i);
            if (empty($n['_valid'])) {
                continue;
            }
            unset($n['_valid']);
            $base = (string)$n['key'];
            $k = $base;
            $nTry = 1;
            while (isset($raw[$k])) {
                $k = $base . '_' . $nTry;
                $nTry++;
            }
            $n['key'] = $k;
            $raw[$k] = $n;
            $i++;
        }
        return self::topoSort(array_values($raw));
    }

    /**
     * @param array<string,mixed> $m
     * @return list<string>
     */
    public static function metricDeps(array $m): array
    {
        $deps = [];
        $kind = isset($m['kind']) ? (string)$m['kind'] : '';
        if ($kind === 'result') {
            $deps = array_merge($deps, self::extractCalcDeps(isset($m['formula']) ? (string)$m['formula'] : ''));
        } else {
            foreach (['a', 'b'] as $f) {
                $ref = isset($m[$f]) ? (string)$m[$f] : '';
                if (strpos($ref, 'calc.') === 0) {
                    $deps[] = substr($ref, 5);
                }
            }
        }
        return array_values(array_unique($deps));
    }

    /**
     * @param list<array<string,mixed>> $list
     * @return list<array<string,mixed>>
     */
    public static function topoSort(array $list): array
    {
        if ($list === []) {
            return [];
        }
        $byKey = [];
        foreach ($list as $m) {
            $byKey[(string)$m['key']] = $m;
        }
        $indeg = [];
        $edges = [];
        foreach ($byKey as $k => $m) {
            $indeg[$k] = 0;
            $edges[$k] = [];
        }
        foreach ($byKey as $k => $m) {
            foreach (self::metricDeps($m) as $d) {
                if (!isset($byKey[$d])) {
                    continue;
                }
                $edges[$d][] = $k;
                $indeg[$k]++;
            }
        }
        $queue = [];
        foreach ($indeg as $k => $deg) {
            if ($deg === 0) {
                $queue[] = $k;
            }
        }
        $ordered = [];
        while ($queue !== []) {
            $k = array_shift($queue);
            $ordered[] = $byKey[$k];
            foreach ($edges[$k] as $to) {
                $indeg[$to]--;
                if ($indeg[$to] === 0) {
                    $queue[] = $to;
                }
            }
        }
        if (count($ordered) < count($byKey)) {
            foreach ($byKey as $k => $m) {
                $found = false;
                foreach ($ordered as $om) {
                    if ($om['key'] === $k) {
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $m['_error'] = 'Circular dependency';
                    $ordered[] = $m;
                }
            }
        }
        return $ordered;
    }

    /**
     * @param array<string,mixed> $req
     * @return list<array<string,mixed>>
     */
    public static function parseFromRequest(array $req): array
    {
        if (!isset($req['computed_metrics']) || !is_array($req['computed_metrics'])) {
            return [];
        }
        $list = [];
        foreach ($req['computed_metrics'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (!isset($row['show_card'])) {
                $row['show_card'] = 0;
            }
            if (!isset($row['show_detail'])) {
                $row['show_detail'] = 0;
            }
            if (!isset($row['target']) || trim((string)$row['target']) === '') {
                $row['has_target'] = 0;
                $row['target'] = '';
            } else {
                $row['has_target'] = 1;
            }
            $list[] = $row;
        }
        return $list;
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,float|int|null> $calcDetail
     * @return array<string,float>
     */
    public static function contextFromRow(array $row, array $calcDetail = []): array
    {
        $vars = [
            'audio_hours' => isset($row['audio_hours']) ? (float)$row['audio_hours'] : 0.0,
            'avg_score' => isset($row['avg_score']) ? (float)$row['avg_score'] : 0.0,
            'script_score' => isset($row['script_score']) ? (float)$row['script_score'] : 0.0,
            'instances' => isset($row['instances']) ? (float)$row['instances'] : 0.0,
            'script_conversion' => isset($row['script_conversion']) ? (float)$row['script_conversion'] : 0.0,
            'days_count' => isset($row['days_count']) ? (float)$row['days_count'] : 0.0,
            'days_pass_score' => isset($row['days_pass_score']) ? (float)$row['days_pass_score'] : 0.0,
            'days_fail_score' => isset($row['days_fail_score']) ? (float)$row['days_fail_score'] : 0.0,
            'script_starts' => isset($row['script_starts']) ? (float)$row['script_starts'] : 0.0,
            'script_finishes' => isset($row['script_finishes']) ? (float)$row['script_finishes'] : 0.0,
        ];
        if (!empty($row['external_detail']) && is_array($row['external_detail'])) {
            foreach ($row['external_detail'] as $ek => $ev) {
                $vars['ext.' . $ek] = (float)$ev;
            }
        }
        if (!empty($row['criteria_pct']) && is_array($row['criteria_pct'])) {
            foreach ($row['criteria_pct'] as $tid => $pct) {
                $vars['crit.' . $tid] = (float)$pct;
            }
        }
        if (!empty($row['tracking_detail']) && is_array($row['tracking_detail'])) {
            foreach ($row['tracking_detail'] as $did => $cnt) {
                $vars['track.' . $did] = (float)$cnt;
            }
        }
        if (!empty($row['scripts_detail']) && is_array($row['scripts_detail'])) {
            foreach ($row['scripts_detail'] as $sid => $sd) {
                if (!is_array($sd)) {
                    continue;
                }
                $vars['script.' . $sid . '.conversion'] = isset($sd['conversion']) ? (float)$sd['conversion'] : 0.0;
                $vars['script.' . $sid . '.score'] = isset($sd['score']) ? (float)$sd['score'] : 0.0;
                $vars['script.' . $sid . '.starts'] = isset($sd['starts']) ? (float)$sd['starts'] : 0.0;
                $vars['script.' . $sid . '.finishes'] = isset($sd['finishes']) ? (float)$sd['finishes'] : 0.0;
            }
        }
        foreach ($calcDetail as $ck => $cv) {
            if ($cv === null) {
                continue;
            }
            $vars['calc.' . $ck] = (float)$cv;
        }
        return $vars;
    }

    /**
     * @param array<string,float|int|null> $vars
     */
    public static function operandValue(string $ref, array $vars): ?float
    {
        $ref = self::normalizeOperandRef($ref);
        if ($ref === '') {
            return null;
        }
        if (!array_key_exists($ref, $vars) || $vars[$ref] === null) {
            return null;
        }
        return (float)$vars[$ref];
    }

    /**
     * @param array<string,mixed> $metric
     */
    public static function applyTarget(?float $value, array $metric): ?int
    {
        if ($value === null) {
            return null;
        }
        if (empty($metric['has_target']) && ($metric['kind'] ?? '') !== 'check') {
            return null;
        }
        $target = isset($metric['target']) ? (float)$metric['target'] : 0.0;
        $cmp = isset($metric['compare']) ? (string)$metric['compare'] : 'gte';
        if ($cmp === 'lte') {
            return ($value <= $target) ? 1 : 0;
        }
        return ($value >= $target) ? 1 : 0;
    }

    /**
     * @param array<string,mixed> $metric
     * @param array<string,float|int|null> $vars
     * @return array{value:float|null,pass:int|null}
     */
    public static function evalMetric(array $metric, array $vars): array
    {
        if (!empty($metric['_error'])) {
            return ['value' => null, 'pass' => null];
        }
        $kind = isset($metric['kind']) ? (string)$metric['kind'] : 'percent';
        $value = null;
        if ($kind === 'percent') {
            $a = self::operandValue(isset($metric['a']) ? (string)$metric['a'] : '', $vars);
            $b = self::operandValue(isset($metric['b']) ? (string)$metric['b'] : '', $vars);
            if ($a === null || $b === null || abs($b) < 1e-12) {
                $value = null;
            } else {
                $value = ($a / $b) * 100.0;
            }
        } elseif ($kind === 'check') {
            $base = isset($metric['check_base']) ? (string)$metric['check_base'] : 'percent';
            if ($base === 'value') {
                $value = self::operandValue(isset($metric['a']) ? (string)$metric['a'] : '', $vars);
            } else {
                $a = self::operandValue(isset($metric['a']) ? (string)$metric['a'] : '', $vars);
                $b = self::operandValue(isset($metric['b']) ? (string)$metric['b'] : '', $vars);
                if ($a === null || $b === null || abs($b) < 1e-12) {
                    $value = null;
                } else {
                    $value = ($a / $b) * 100.0;
                }
            }
        } elseif ($kind === 'result') {
            $formula = isset($metric['formula']) ? (string)$metric['formula'] : '';
            $value = ($formula !== '') ? self::evalFormula($formula, $vars) : null;
        }
        $pass = self::applyTarget($value, $metric);
        return ['value' => $value, 'pass' => $pass];
    }

    /**
     * @param list<array<string,mixed>> $metrics
     * @param array<string,mixed> $row
     * @return array{detail:array<string,float|null>,pass:array<string,int|null>}
     */
    public static function evalList(array $metrics, array $row): array
    {
        $detail = [];
        $passMap = [];
        if ($metrics === []) {
            return ['detail' => $detail, 'pass' => $passMap];
        }
        foreach ($metrics as $m) {
            $vars = self::contextFromRow($row, $detail);
            $ev = self::evalMetric($m, $vars);
            $k = (string)$m['key'];
            $detail[$k] = $ev['value'];
            $passMap[$k] = $ev['pass'];
        }
        return ['detail' => $detail, 'pass' => $passMap];
    }

    // --- formula parser internals ---

    /** @param list<array<string,mixed>> $tokens */
    private static function peek(array $tokens, int $pos): ?array
    {
        return isset($tokens[$pos]) ? $tokens[$pos] : null;
    }

    /**
     * @param list<array<string,mixed>> $tokens
     * @param array<string,float|int|null> $vars
     * @return float|null
     */
    private static function parseCmp(array &$tokens, int &$pos, array $vars, bool &$nullFlag): ?float
    {
        $left = self::parseAdd($tokens, $pos, $vars, $nullFlag);
        $tok = self::peek($tokens, $pos);
        if ($tok && $tok['t'] === 'cmp') {
            $pos++;
            $right = self::parseAdd($tokens, $pos, $vars, $nullFlag);
            if ($nullFlag || $left === null || $right === null) {
                $nullFlag = true;
                return null;
            }
            $op = (string)$tok['v'];
            if ($op === '=') {
                return ($left == $right) ? 1.0 : 0.0;
            }
            if ($op === '<>') {
                return ($left != $right) ? 1.0 : 0.0;
            }
            if ($op === '<') {
                return ($left < $right) ? 1.0 : 0.0;
            }
            if ($op === '>') {
                return ($left > $right) ? 1.0 : 0.0;
            }
            if ($op === '<=') {
                return ($left <= $right) ? 1.0 : 0.0;
            }
            if ($op === '>=') {
                return ($left >= $right) ? 1.0 : 0.0;
            }
            $nullFlag = true;
            return null;
        }
        return $left;
    }

    /**
     * @param list<array<string,mixed>> $tokens
     * @param array<string,float|int|null> $vars
     */
    private static function parseAdd(array &$tokens, int &$pos, array $vars, bool &$nullFlag): ?float
    {
        $left = self::parseMul($tokens, $pos, $vars, $nullFlag);
        while (true) {
            $tok = self::peek($tokens, $pos);
            if (!$tok || $tok['t'] !== 'op' || ($tok['v'] !== '+' && $tok['v'] !== '-')) {
                break;
            }
            $pos++;
            $right = self::parseMul($tokens, $pos, $vars, $nullFlag);
            if ($nullFlag || $left === null || $right === null) {
                $nullFlag = true;
                return null;
            }
            $left = ($tok['v'] === '+') ? ($left + $right) : ($left - $right);
        }
        return $left;
    }

    /**
     * @param list<array<string,mixed>> $tokens
     * @param array<string,float|int|null> $vars
     */
    private static function parseMul(array &$tokens, int &$pos, array $vars, bool &$nullFlag): ?float
    {
        $left = self::parseUnary($tokens, $pos, $vars, $nullFlag);
        while (true) {
            $tok = self::peek($tokens, $pos);
            if (!$tok || $tok['t'] !== 'op' || ($tok['v'] !== '*' && $tok['v'] !== '/')) {
                break;
            }
            $pos++;
            $right = self::parseUnary($tokens, $pos, $vars, $nullFlag);
            if ($nullFlag || $left === null || $right === null) {
                $nullFlag = true;
                return null;
            }
            if ($tok['v'] === '/') {
                if (abs($right) < 1e-12) {
                    $nullFlag = true;
                    return null;
                }
                $left = $left / $right;
            } else {
                $left = $left * $right;
            }
        }
        return $left;
    }

    /**
     * @param list<array<string,mixed>> $tokens
     * @param array<string,float|int|null> $vars
     */
    private static function parseUnary(array &$tokens, int &$pos, array $vars, bool &$nullFlag): ?float
    {
        $tok = self::peek($tokens, $pos);
        if ($tok && $tok['t'] === 'op' && $tok['v'] === '-') {
            $pos++;
            $v = self::parseUnary($tokens, $pos, $vars, $nullFlag);
            if ($nullFlag || $v === null) {
                $nullFlag = true;
                return null;
            }
            return -$v;
        }
        if ($tok && $tok['t'] === 'op' && $tok['v'] === '+') {
            $pos++;
            return self::parseUnary($tokens, $pos, $vars, $nullFlag);
        }
        return self::parsePrimary($tokens, $pos, $vars, $nullFlag);
    }

    /**
     * @param list<array<string,mixed>> $tokens
     * @param array<string,float|int|null> $vars
     */
    private static function parsePrimary(array &$tokens, int &$pos, array $vars, bool &$nullFlag): ?float
    {
        $tok = self::peek($tokens, $pos);
        if (!$tok) {
            $nullFlag = true;
            return null;
        }
        if ($tok['t'] === 'num') {
            $pos++;
            return (float)$tok['v'];
        }
        if ($tok['t'] === 'ref') {
            $pos++;
            $key = (string)$tok['v'];
            if (!array_key_exists($key, $vars) || $vars[$key] === null) {
                $nullFlag = true;
                return null;
            }
            return (float)$vars[$key];
        }
        if ($tok['t'] === 'id') {
            $fname = (string)$tok['v'];
            $pos++;
            $next = self::peek($tokens, $pos);
            if (!$next || $next['t'] !== 'lp') {
                $nullFlag = true;
                return null;
            }
            $pos++;
            if ($fname === 'IF') {
                $cond = self::parseCmp($tokens, $pos, $vars, $nullFlag);
                $next = self::peek($tokens, $pos);
                if (!$next || $next['t'] !== 'comma') {
                    $nullFlag = true;
                    return null;
                }
                $pos++;
                if ($nullFlag || $cond === null) {
                    self::skipExpr($tokens, $pos);
                    $next = self::peek($tokens, $pos);
                    if ($next && $next['t'] === 'comma') {
                        $pos++;
                        self::skipExpr($tokens, $pos);
                    }
                    $next = self::peek($tokens, $pos);
                    if (!$next || $next['t'] !== 'rp') {
                        $nullFlag = true;
                        return null;
                    }
                    $pos++;
                    return null;
                }
                $takeThen = ((float)$cond != 0.0);
                if ($takeThen) {
                    $val = self::parseCmp($tokens, $pos, $vars, $nullFlag);
                    $next = self::peek($tokens, $pos);
                    if (!$next || $next['t'] !== 'comma') {
                        $nullFlag = true;
                        return null;
                    }
                    $pos++;
                    self::skipExpr($tokens, $pos);
                } else {
                    self::skipExpr($tokens, $pos);
                    $next = self::peek($tokens, $pos);
                    if (!$next || $next['t'] !== 'comma') {
                        $nullFlag = true;
                        return null;
                    }
                    $pos++;
                    $val = self::parseCmp($tokens, $pos, $vars, $nullFlag);
                }
                $next = self::peek($tokens, $pos);
                if (!$next || $next['t'] !== 'rp') {
                    $nullFlag = true;
                    return null;
                }
                $pos++;
                return $val;
            }
            $args = [];
            $next = self::peek($tokens, $pos);
            if ($next && $next['t'] !== 'rp') {
                while (true) {
                    $args[] = self::parseCmp($tokens, $pos, $vars, $nullFlag);
                    $next = self::peek($tokens, $pos);
                    if ($next && $next['t'] === 'comma') {
                        $pos++;
                        continue;
                    }
                    break;
                }
            }
            $next = self::peek($tokens, $pos);
            if (!$next || $next['t'] !== 'rp') {
                $nullFlag = true;
                return null;
            }
            $pos++;
            return self::callFn($fname, $args, $nullFlag);
        }
        if ($tok['t'] === 'lp') {
            $pos++;
            $v = self::parseCmp($tokens, $pos, $vars, $nullFlag);
            $next = self::peek($tokens, $pos);
            if (!$next || $next['t'] !== 'rp') {
                $nullFlag = true;
                return null;
            }
            $pos++;
            return $v;
        }
        $nullFlag = true;
        return null;
    }

    /**
     * @param list<?float> $args
     */
    private static function callFn(string $fname, array $args, bool &$nullFlag): ?float
    {
        $fname = strtoupper($fname);
        if ($fname === 'ABS') {
            if (count($args) !== 1 || $args[0] === null) {
                $nullFlag = true;
                return null;
            }
            return abs((float)$args[0]);
        }
        if ($fname === 'ROUND') {
            if (count($args) < 1 || count($args) > 2 || $args[0] === null) {
                $nullFlag = true;
                return null;
            }
            $digits = isset($args[1]) ? (int)$args[1] : 0;
            if ($digits < 0) {
                $digits = 0;
            }
            if ($digits > 8) {
                $digits = 8;
            }
            return round((float)$args[0], $digits);
        }
        if ($fname === 'MIN' || $fname === 'MAX') {
            if (count($args) < 1) {
                $nullFlag = true;
                return null;
            }
            $acc = null;
            foreach ($args as $a) {
                if ($a === null) {
                    $nullFlag = true;
                    return null;
                }
                $a = (float)$a;
                if ($acc === null) {
                    $acc = $a;
                } else {
                    $acc = ($fname === 'MIN') ? min($acc, $a) : max($acc, $a);
                }
            }
            return $acc;
        }
        $nullFlag = true;
        return null;
    }

    /** @param list<array<string,mixed>> $tokens */
    private static function skipExpr(array &$tokens, int &$pos): void
    {
        $depth = 0;
        while ($pos < count($tokens)) {
            $tok = $tokens[$pos];
            if ($tok['t'] === 'lp') {
                $depth++;
                $pos++;
                continue;
            }
            if ($tok['t'] === 'rp') {
                if ($depth === 0) {
                    return;
                }
                $depth--;
                $pos++;
                continue;
            }
            if (($tok['t'] === 'comma') && $depth === 0) {
                return;
            }
            $pos++;
        }
    }
}
