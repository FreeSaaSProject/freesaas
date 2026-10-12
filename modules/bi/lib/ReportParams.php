<?php
declare(strict_types=1);

/**
 * Allowlisted params for KPI report families (run + share constraints).
 */
final class ReportParams
{
    public const TTL_KINDS = ['1h', '1d', '1mo', '3mo', '1y'];

    /** TTL kinds allowed for guest shares (no year-long guest links). */
    public const TTL_KINDS_GUEST = ['1h', '1d', '1mo', '3mo'];

    public const AUDIENCES = ['guest', 'staff'];

    /**
     * @param array<string,mixed> $raw
     * @return array{
     *   year:int,month:int,dayshow:int,
     *   id_operator:int,id_admin:int,id_good:int,
     *   variant:string
     * }
     */
    public static function normalize(array $raw): array
    {
        $year = (int)($raw['year'] ?? date('Y'));
        if ($year < 2000 || $year > 2100) {
            $year = (int)date('Y');
        }
        $month = (int)($raw['month'] ?? date('n'));
        if ($month < 1 || $month > 12) {
            $month = (int)date('n');
        }
        $variant = strtolower(trim((string)($raw['variant'] ?? '')));
        if ($variant !== '' && !in_array($variant, ['ppa', 'full'], true)) {
            $variant = 'full';
        }
        if ($variant === '') {
            $variant = 'full';
        }

        return [
            'year' => $year,
            'month' => $month,
            'dayshow' => !empty($raw['dayshow']) ? 1 : 0,
            'id_operator' => max(0, (int)($raw['id_operator'] ?? 0)),
            'id_admin' => max(0, (int)($raw['id_admin'] ?? 0)),
            'id_good' => max(0, (int)($raw['id_good'] ?? 0)),
            'variant' => $variant,
        ];
    }

    /**
     * Merge request params with share constraints (share wins on fixed keys).
     *
     * @param array<string,mixed> $request
     * @param array<string,mixed> $constraints from share PARAMS_JSON
     * @return array<string,mixed>
     */
    public static function mergeWithConstraints(array $request, array $constraints): array
    {
        $base = self::normalize($request);
        $out = $base;
        // Period always from request (whole-family live access).
        // Apply only sparse locked filters from the share.
        foreach (['id_operator', 'id_admin', 'id_good'] as $k) {
            if (isset($constraints[$k]) && (int)$constraints[$k] > 0) {
                $out[$k] = (int)$constraints[$k];
            }
        }
        if (isset($constraints['variant']) && trim((string)$constraints['variant']) !== '') {
            $v = strtolower(trim((string)$constraints['variant']));
            if (in_array($v, ['ppa', 'full'], true)) {
                $out['variant'] = $v;
            }
        }
        return $out;
    }

    /**
     * @return array{start:int,end:int,label:string}
     */
    public static function monthBounds(int $year, int $month): array
    {
        $start = (int)mktime(0, 0, 0, $month, 1, $year);
        $end = (int)mktime(23, 59, 59, $month, (int)date('t', $start), $year);
        return [
            'start' => $start,
            'end' => $end,
            'label' => sprintf('%04d-%02d', $year, $month),
        ];
    }

    /**
     * @return array{start:int,end:int}
     */
    public static function dayBounds(int $year, int $month, int $day): array
    {
        return [
            'start' => (int)mktime(0, 0, 0, $month, $day, $year),
            'end' => (int)mktime(23, 59, 59, $month, $day, $year),
        ];
    }

    public static function ttlSeconds(string $kind): int
    {
        switch ($kind) {
            case '1h':
                return 3600;
            case '1d':
                return 86400;
            case '1mo':
                return 86400 * 30;
            case '3mo':
                return 86400 * 90;
            case '1y':
                return 86400 * 365;
            default:
                return 86400;
        }
    }

    public static function normalizeTtl(string $kind, string $audience = ''): string
    {
        $kind = strtolower(trim($kind));
        if (!in_array($kind, self::TTL_KINDS, true)) {
            return '1d';
        }
        $aud = $audience !== '' ? self::normalizeAudience($audience) : '';
        if ($aud === 'guest' && !in_array($kind, self::TTL_KINDS_GUEST, true)) {
            return '1d';
        }
        return $kind;
    }

    public static function normalizeAudience(string $audience): string
    {
        $audience = strtolower(trim($audience));
        return in_array($audience, self::AUDIENCES, true) ? $audience : 'guest';
    }
}
