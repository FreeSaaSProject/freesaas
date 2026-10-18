<?php
declare(strict_types=1);

/** Promoter-day detection, bonus criteria, BONUS TIME records. */
final class PromoterBonus
{
    public const MARKER = 'BONUS TIME';

    /** @var array{cover_from:int,cover_to:int,days:array<int,array<string,true>>}|null */
    private static $promoterDays = null;

    /** @var array{cover_from:int,cover_to:int,by_admin_day:array,days_with_data:array<string,true>}|null */
    private static $termScanCache = null;

    /** @var array{range:string,scripts:array,cover_from?:int,cover_to?:int}|null */
    private static $scriptsCache = null;

    /** @var array{cover_from:int,cover_to:int,bonus:array<string,true>}|null */
    private static $bonusCache = null;

    public static function resetRequestCache(): void
    {
        self::$promoterDays = null;
        self::$termScanCache = null;
        self::$scriptsCache = null;
        self::$bonusCache = null;
    }

    /**
     * Seed scripts cache from an already-loaded CalendarAggregates::scriptsForRange result
     * so promoter cells do not re-query collections for the same period.
     *
     * @param array{by_admin_day?:array} $scriptsAgg
     */
    public static function warmScriptsCache(array $scriptsAgg, int $fromTs, int $toTs): void
    {
        if ($fromTs < 1 || $toTs < $fromTs) {
            return;
        }
        self::$scriptsCache = [
            'range' => 'warm',
            'cover_from' => $fromTs,
            'cover_to' => $toTs,
            'scripts' => $scriptsAgg,
        ];
    }

    /**
     * Prefetch promoter-day / term-scan / BONUS TIME maps for a calendar window.
     * $toTsExclusive is the first instant after the last included day.
     */
    public static function warmForRange(int $fromTs, int $toTsExclusive): void
    {
        if ($fromTs < 1 || $toTsExclusive <= $fromTs) {
            return;
        }
        if (LikespaConfig::promoEnabled()) {
            self::ensurePromoterDays($fromTs, $toTsExclusive);
            if (TermScansRepository::tableReady()) {
                self::ensureTermScanCache($fromTs, $toTsExclusive);
            }
            self::ensureBonusCache($fromTs, $toTsExclusive);
        }
        if (LikespaConfig::dopsEnabled()) {
            DopsStats::warmForRange($fromTs, $toTsExclusive);
        }
    }

    private static function ensurePromoterDays(int $from, int $to): void
    {
        if (!Database::tableExists('base_workweeks')) {
            return;
        }
        if (self::$promoterDays !== null
            && (int)(self::$promoterDays['cover_from'] ?? 0) <= $from
            && (int)(self::$promoterDays['cover_to'] ?? 0) >= $to
        ) {
            return;
        }
        self::$promoterDays = ['cover_from' => $from, 'cover_to' => $to, 'days' => []];
        $st = Database::pdo()->prepare(
            'SELECT ID_OPERATOR AS id_operator, DT AS dt
             FROM base_workweeks
             WHERE DT >= ? AND DT < ? AND PRICE_COL = ?'
        );
        $st->execute([$from, $to, self::priceCol()]);
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $oid = (int)($row['id_operator'] ?? 0);
            $dt = (int)($row['dt'] ?? 0);
            if ($oid < 1 || $dt < 1) {
                continue;
            }
            self::$promoterDays['days'][$oid][date('Y-m-d', $dt)] = true;
        }
    }

    private static function ensureTermScanCache(int $from, int $to): void
    {
        if (self::$termScanCache !== null
            && (int)(self::$termScanCache['cover_from'] ?? 0) <= $from
            && (int)(self::$termScanCache['cover_to'] ?? 0) >= $to
        ) {
            return;
        }
        $window = LikespaConfig::int('promo_term_scan_proc_window_hours', 4);
        $byAdmin = TermScansRepository::buildAdminDayCache($from, $to, $window);
        $daysWith = [];
        foreach ($byAdmin as $days) {
            foreach ($days as $d => $_) {
                $daysWith[$d] = true;
            }
        }
        self::$termScanCache = [
            'cover_from' => $from,
            'cover_to' => $to,
            'by_admin_day' => $byAdmin,
            'days_with_data' => $daysWith,
        ];
    }

    private static function ensureBonusCache(int $from, int $to): void
    {
        if (self::$bonusCache !== null
            && (int)(self::$bonusCache['cover_from'] ?? 0) <= $from
            && (int)(self::$bonusCache['cover_to'] ?? 0) >= $to
        ) {
            return;
        }
        self::$bonusCache = ['cover_from' => $from, 'cover_to' => $to, 'bonus' => []];
        if (!Database::tableExists('base_records') || !Database::columnExists('base_records', 'DESCRIPTION')) {
            return;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID_ADMIN AS id_admin, DT_RECORDED AS dt
             FROM base_records
             WHERE DT_RECORDED >= ? AND DT_RECORDED < ?
               AND DESCRIPTION = ?
               AND (DISABLED IS NULL OR DISABLED < 1)'
        );
        $st->execute([$from, $to, self::MARKER]);
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $aid = (int)($row['id_admin'] ?? 0);
            $dt = (int)($row['dt'] ?? 0);
            if ($aid > 0 && $dt > 0) {
                self::$bonusCache['bonus'][$aid . '|' . date('Y-m-d', $dt)] = true;
            }
        }
    }

    public static function priceCol(): int
    {
        return max(1, LikespaConfig::int('promo_price_col', 4));
    }

    /** @return list<int> */
    public static function scriptIds(): array
    {
        return LikespaConfig::intList('promo_script_ids', [4, 20]);
    }

    /**
     * @return array{0:int,1:int} month [fromTs, toTsExclusive] containing $date Y-m-d
     */
    public static function monthRange(string $date): array
    {
        $ts = strtotime($date . ' 00:00:00');
        if ($ts === false) {
            $ts = time();
        }
        $y = (int)date('Y', $ts);
        $m = (int)date('n', $ts);
        $from = (int)mktime(0, 0, 0, $m, 1, $y);
        $to = (int)mktime(0, 0, 0, $m + 1, 1, $y);
        return [$from, $to];
    }

    public static function isPromoterOnDay(int $adminId, string $date): bool
    {
        if ($adminId < 1 || $date === '' || !LikespaConfig::promoEnabled()) {
            return false;
        }
        if (!Database::tableExists('base_workweeks')) {
            return false;
        }
        [$from, $to] = self::monthRange($date);
        self::ensurePromoterDays($from, $to);
        return isset(self::$promoterDays['days'][$adminId][$date]);
    }

    /**
     * @return array{scans:int,unique_clients:int,unique_clients_with_procedures:int,procedure_conversion:float}
     */
    public static function termScanDay(int $adminId, string $date): array
    {
        $empty = [
            'scans' => 0,
            'unique_clients' => 0,
            'unique_clients_with_procedures' => 0,
            'procedure_conversion' => 0.0,
        ];
        if ($adminId < 1 || $date === '' || !TermScansRepository::tableReady()) {
            return $empty;
        }
        [$from, $to] = self::monthRange($date);
        self::ensureTermScanCache($from, $to);
        $data = self::$termScanCache['by_admin_day'][$adminId][$date] ?? null;
        if (!is_array($data)) {
            return $empty;
        }
        $clients = (int)($data['unique_clients'] ?? 0);
        $withProc = (int)($data['unique_clients_with_procedures'] ?? 0);
        $pct = $clients > 0 ? round($withProc * 100 / $clients, 1) : 0.0;
        return [
            'scans' => (int)($data['scans'] ?? 0),
            'unique_clients' => $clients,
            'unique_clients_with_procedures' => $withProc,
            'procedure_conversion' => $pct,
        ];
    }

    public static function termScanDayHasData(string $date): bool
    {
        if ($date === '' || !TermScansRepository::tableReady()) {
            return false;
        }
        // Warm cache via termScanDay.
        self::termScanDay(0, $date);
        return isset(self::$termScanCache['days_with_data'][$date]);
    }

    /**
     * @return array{script_id:int,script_count:int,finish_count:int,script_finish_conversion:float}
     */
    public static function scriptPick(int $adminId, string $date): array
    {
        $ids = self::scriptIds();
        $empty = [
            'script_id' => $ids[0] ?? 0,
            'script_count' => 0,
            'finish_count' => 0,
            'script_finish_conversion' => 0.0,
        ];
        if ($adminId < 1 || $date === '' || $ids === []) {
            return $empty;
        }
        if (!class_exists('CalendarAggregates', false)) {
            return $empty;
        }
        [$from, $to] = self::monthRange($date);
        $key = $from . '-' . $to;
        $cached = self::$scriptsCache;
        $covers = is_array($cached)
            && ($cached['range'] ?? '') === 'warm'
            && (int)($cached['cover_from'] ?? 0) <= $from
            && (int)($cached['cover_to'] ?? 0) >= ($to - 1);
        if (!$covers && ($cached === null || (string)($cached['range'] ?? '') !== $key)) {
            self::$scriptsCache = [
                'range' => $key,
                'scripts' => CalendarAggregates::scriptsForRange($from, $to - 1),
            ];
        }
        $byAdminDay = self::$scriptsCache['scripts']['by_admin_day'] ?? [];
        $bestId = 0;
        $bestCount = 0;
        $bestFinish = 0;
        $bestConv = 0.0;
        foreach ($ids as $sid) {
            $cell = $byAdminDay[$adminId][$sid][$date] ?? null;
            $c = is_array($cell) ? (int)($cell['starts'] ?? 0) : 0;
            $f = is_array($cell) ? (int)($cell['finishes'] ?? 0) : 0;
            $conv = $c > 0 ? ($f * 100) / $c : 0.0;
            if ($c > $bestCount) {
                $bestCount = $c;
                $bestId = $sid;
                $bestFinish = $f;
                $bestConv = $conv;
            } elseif ($c > 0 && $c === $bestCount) {
                if ($conv > $bestConv || ($conv === $bestConv && ($bestId === 0 || $sid < $bestId))) {
                    $bestId = $sid;
                    $bestFinish = $f;
                    $bestConv = $conv;
                }
            }
        }
        if ($bestId === 0) {
            $bestId = $ids[0];
        }
        return [
            'script_id' => $bestId,
            'script_count' => $bestCount,
            'finish_count' => $bestFinish,
            'script_finish_conversion' => $bestCount > 0
                ? round(($bestFinish * 100) / $bestCount, 2)
                : 0.0,
        ];
    }

    /**
     * @param array{playtime?:int,score_w?:float,score_sec?:int} $aggregates
     * @return array{
     *   work_hours:float,script_id:int,script_count:int,finish_count:int,
     *   scan_unique_clients:int,conversion_mode:string,conversion:float,
     *   eligible:bool,has_bonus:bool,term:array
     * }
     */
    public static function metrics(int $adminId, string $date, array $aggregates): array
    {
        $playtime = max(0, (int)($aggregates['playtime'] ?? 0));
        $workHours = $playtime / 3600;
        $pick = self::scriptPick($adminId, $date);
        $term = self::termScanDay($adminId, $date);
        $scriptCount = (int)$pick['script_count'];
        $scanClients = (int)$term['unique_clients'];
        $useScans = LikespaConfig::flag('promo_term_scan_conversion', true)
            && self::termScanDayHasData($date);
        $conversionMode = $useScans ? 'scans' : 'script_finish';
        $conversion = 0.0;
        if ($useScans) {
            if ($scriptCount > 0) {
                $conversion = round($scanClients * 100 / $scriptCount, 2);
            }
        } else {
            $conversion = (float)$pick['script_finish_conversion'];
        }

        $eligible = $workHours > LikespaConfig::float('promo_min_work_hours', 6.8)
            && $scriptCount > LikespaConfig::int('promo_min_script_count', 95)
            && $conversion > LikespaConfig::float('promo_min_conversion', 20);

        return [
            'work_hours' => round($workHours, 2),
            'script_id' => (int)$pick['script_id'],
            'script_count' => $scriptCount,
            'finish_count' => (int)$pick['finish_count'],
            'scan_unique_clients' => $scanClients,
            'conversion_mode' => $conversionMode,
            'conversion' => $conversion,
            'eligible' => $eligible,
            'has_bonus' => self::hasBonusRecord($adminId, $date),
            'term' => $term,
        ];
    }

    public static function hasBonusRecord(int $adminId, string $date): bool
    {
        if ($adminId < 1 || $date === '' || !Database::tableExists('base_records')) {
            return false;
        }
        [$from, $to] = self::monthRange($date);
        self::ensureBonusCache($from, $to);
        return isset(self::$bonusCache['bonus'][$adminId . '|' . $date]);
    }

}
