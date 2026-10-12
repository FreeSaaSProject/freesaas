<?php
declare(strict_types=1);

/**
 * Safe JSON-AST expression evaluator for BI recipes (no string eval).
 */
final class BiExpr
{
    /** @var list<string> */
    private const WEEKDAY_RU = ['ВС', 'Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб'];

    /**
     * @param mixed $expr
     * @param array<string,mixed> $row
     * @return mixed
     */
    public static function eval($expr, array $row)
    {
        if (is_int($expr) || is_float($expr) || is_bool($expr) || $expr === null) {
            return $expr;
        }
        if (is_string($expr)) {
            return $row[$expr] ?? null;
        }
        if (!is_array($expr)) {
            throw new InvalidArgumentException('expr_invalid');
        }
        if (array_key_exists('lit', $expr)) {
            return $expr['lit'];
        }
        if (isset($expr['field'])) {
            $f = (string)$expr['field'];
            return $row[$f] ?? null;
        }

        $ops = [
            'add', 'sub', 'mul', 'div',
            'eq', 'neq', 'gt', 'gte', 'lt', 'lte',
            'and', 'or', 'not',
            'if', 'coalesce', 'nullif',
            'min', 'max', 'floor', 'round', 'concat',
            'in', 'date_label', 'format_ts', 'acronym',
        ];
        foreach ($ops as $op) {
            if (!array_key_exists($op, $expr)) {
                continue;
            }
            $args = $expr[$op];
            switch ($op) {
                case 'not':
                    return !self::truthy(self::eval($args, $row));
                case 'acronym':
                    // System shortName / CalendarTimeline::acronym — initials of each word.
                    $name = (string)self::eval(is_array($args) ? ($args[0] ?? '') : $args, $row);
                    if (class_exists('UsersRepository', false)) {
                        $short = UsersRepository::shortName($name, 1);
                        return $short !== '' ? $short : '?';
                    }
                    if (class_exists('CalendarTimeline', false)) {
                        return CalendarTimeline::acronym($name);
                    }
                    return $name !== '' ? mb_strtoupper(mb_substr($name, 0, 1, 'UTF-8'), 'UTF-8') : '?';
                case 'if':
                    if (!is_array($args) || count($args) < 3) {
                        throw new InvalidArgumentException('expr_if_arity');
                    }
                    return self::truthy(self::eval($args[0], $row))
                        ? self::eval($args[1], $row)
                        : self::eval($args[2], $row);
                case 'coalesce':
                    if (!is_array($args)) {
                        return self::eval($args, $row);
                    }
                    foreach ($args as $a) {
                        $v = self::eval($a, $row);
                        if ($v !== null && $v !== '') {
                            return $v;
                        }
                    }
                    return null;
                case 'nullif':
                    if (!is_array($args) || count($args) < 2) {
                        throw new InvalidArgumentException('expr_nullif_arity');
                    }
                    $a = self::eval($args[0], $row);
                    $b = self::eval($args[1], $row);
                    return self::same($a, $b) ? null : $a;
                case 'and':
                    if (!is_array($args)) {
                        return self::truthy(self::eval($args, $row));
                    }
                    foreach ($args as $a) {
                        if (!self::truthy(self::eval($a, $row))) {
                            return false;
                        }
                    }
                    return true;
                case 'or':
                    if (!is_array($args)) {
                        return self::truthy(self::eval($args, $row));
                    }
                    foreach ($args as $a) {
                        if (self::truthy(self::eval($a, $row))) {
                            return true;
                        }
                    }
                    return false;
                case 'min':
                case 'max':
                    if (!is_array($args) || $args === []) {
                        throw new InvalidArgumentException('expr_' . $op . '_arity');
                    }
                    $vals = [];
                    foreach ($args as $a) {
                        $vals[] = (float)self::eval($a, $row);
                    }
                    return $op === 'min' ? min($vals) : max($vals);
                case 'floor':
                    return (float)floor((float)self::eval(is_array($args) ? ($args[0] ?? 0) : $args, $row));
                case 'round':
                    if (!is_array($args) || count($args) < 1) {
                        throw new InvalidArgumentException('expr_round_arity');
                    }
                    $n = (float)self::eval($args[0], $row);
                    $prec = isset($args[1]) ? (int)self::eval($args[1], $row) : 0;
                    return round($n, max(0, min(10, $prec)));
                case 'concat':
                    if (!is_array($args)) {
                        return (string)self::eval($args, $row);
                    }
                    $parts = [];
                    foreach ($args as $a) {
                        $v = self::eval($a, $row);
                        $parts[] = $v === null ? '' : (string)$v;
                    }
                    return implode('', $parts);
                case 'in':
                    // {"in": [valueExpr, [lit, lit, …]]} or {"in": [valueExpr, lit, lit, …]}
                    if (!is_array($args) || count($args) < 2) {
                        throw new InvalidArgumentException('expr_in_arity');
                    }
                    $needle = self::eval($args[0], $row);
                    $hay = $args[1];
                    if (is_array($hay) && array_key_exists('lit', $hay)) {
                        $hay = $hay['lit'];
                    }
                    if (!is_array($hay)) {
                        $hay = array_slice($args, 1);
                    }
                    foreach ($hay as $h) {
                        $hv = is_array($h) && (isset($h['lit']) || isset($h['field']) || self::looksLikeOp($h))
                            ? self::eval($h, $row)
                            : $h;
                        if (self::same($needle, $hv)) {
                            return true;
                        }
                    }
                    return false;
                case 'format_ts':
                    if (!is_array($args) || count($args) < 2) {
                        throw new InvalidArgumentException('expr_format_ts_arity');
                    }
                    $ts = (int)self::eval($args[0], $row);
                    $fmt = (string)self::eval($args[1], $row);
                    return $ts > 0 ? date($fmt, $ts) : '';
                case 'date_label':
                    // Oper-style: "12.10.2026 [Пн]"
                    $ts = (int)self::eval(is_array($args) ? ($args[0] ?? 0) : $args, $row);
                    if ($ts <= 0) {
                        return '';
                    }
                    $w = (int)date('w', $ts);
                    return date('d.m.Y', $ts) . ' [' . (self::WEEKDAY_RU[$w] ?? '') . ']';
                case 'add':
                case 'sub':
                case 'mul':
                case 'div':
                case 'eq':
                case 'neq':
                case 'gt':
                case 'gte':
                case 'lt':
                case 'lte':
                    if (!is_array($args) || count($args) < 2) {
                        throw new InvalidArgumentException('expr_bin_arity');
                    }
                    $left = self::eval($args[0], $row);
                    $right = self::eval($args[1], $row);
                    return self::bin($op, $left, $right);
            }
        }
        throw new InvalidArgumentException('expr_unknown_op');
    }

    /**
     * Lightweight structural check (ops only).
     *
     * @param mixed $expr
     * @param list<string> $errors
     */
    public static function validate($expr, string $path, array &$errors): void
    {
        if (is_int($expr) || is_float($expr) || is_bool($expr) || $expr === null || is_string($expr)) {
            return;
        }
        if (!is_array($expr)) {
            $errors[] = $path . ': invalid expr';
            return;
        }
        if (array_key_exists('lit', $expr) || isset($expr['field'])) {
            return;
        }
        $found = false;
        foreach (BiDatasetCatalog::exprOps() as $op) {
            if ($op === 'field' || $op === 'lit') {
                continue;
            }
            if (!array_key_exists($op, $expr)) {
                continue;
            }
            $found = true;
            $args = $expr[$op];
            if ($op === 'not' || $op === 'floor' || $op === 'date_label' || $op === 'acronym') {
                if (is_array($args) && !isset($args['lit']) && !isset($args['field']) && !self::looksLikeOp($args)) {
                    self::validate($args[0] ?? $args, $path . '.' . $op, $errors);
                } else {
                    self::validate($args, $path . '.' . $op, $errors);
                }
            } elseif ($op === 'in') {
                if (!is_array($args) || count($args) < 2) {
                    $errors[] = $path . '.in: need value + list';
                } else {
                    self::validate($args[0], $path . '.in[0]', $errors);
                }
            } elseif (is_array($args)) {
                foreach ($args as $i => $a) {
                    // Plain scalars in concat/in lists are fine.
                    if (is_int($a) || is_float($a) || is_bool($a) || $a === null || is_string($a)) {
                        continue;
                    }
                    self::validate($a, $path . '.' . $op . '[' . $i . ']', $errors);
                }
            } else {
                $errors[] = $path . '.' . $op . ': expected array args';
            }
            break;
        }
        if (!$found) {
            $errors[] = $path . ': unknown expr op';
        }
    }

    /**
     * @param array<string,mixed> $expr
     */
    private static function looksLikeOp(array $expr): bool
    {
        foreach (BiDatasetCatalog::exprOps() as $op) {
            if ($op === 'field' || $op === 'lit') {
                continue;
            }
            if (array_key_exists($op, $expr)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param mixed $left
     * @param mixed $right
     * @return mixed
     */
    private static function bin(string $op, $left, $right)
    {
        switch ($op) {
            case 'add':
                return (float)$left + (float)$right;
            case 'sub':
                return (float)$left - (float)$right;
            case 'mul':
                return (float)$left * (float)$right;
            case 'div':
                $r = (float)$right;
                return abs($r) < 1e-12 ? null : ((float)$left / $r);
            case 'eq':
                return self::same($left, $right);
            case 'neq':
                return !self::same($left, $right);
            case 'gt':
                return (float)$left > (float)$right;
            case 'gte':
                return (float)$left >= (float)$right;
            case 'lt':
                return (float)$left < (float)$right;
            case 'lte':
                return (float)$left <= (float)$right;
        }
        return null;
    }

    /**
     * @param mixed $a
     * @param mixed $b
     */
    private static function same($a, $b): bool
    {
        if (is_numeric($a) && is_numeric($b)) {
            return (float)$a === (float)$b;
        }
        return $a === $b;
    }

    /**
     * @param mixed $v
     */
    public static function truthy($v): bool
    {
        return !($v === null || $v === false || $v === 0 || $v === 0.0 || $v === '' || $v === []);
    }
}
