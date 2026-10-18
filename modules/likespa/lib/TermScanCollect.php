<?php
declare(strict_types=1);

/**
 * Attach unique bracelet scans to script collections (SCORE_RATE + quality metric).
 *
 * Only scans in a short window AFTER an opening collection count — orphan scans
 * with no preceding communication are ignored (dictionary-session analogy).
 */
final class TermScanCollect
{
    /** Default post-communication window when part TIMEOUT/COND_VALUE are 0. */
    public const DEFAULT_WINDOW_SEC = 600;

    /**
     * After CollectRunner rebuilt collections for one admin — bump SCORE_RATE.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public static function onCollectAfterAdmin($payload)
    {
        if (!is_array($payload)) {
            return $payload;
        }
        if (!TermScansRepository::tableReady() || !LikespaConfig::flag('term_scans_enabled', true)) {
            return $payload;
        }
        $adminId = (int)($payload['admin_id'] ?? 0);
        if ($adminId < 1) {
            return $payload;
        }
        $winStart = (int)($payload['window_start'] ?? 0);
        $winEnd = (int)($payload['window_end'] ?? 0);
        $attached = self::attachForAdmin($adminId, $winStart, $winEnd);
        $payload['term_scan_attached'] = $attached;

        return $payload;
    }

    /**
     * Unique clients matched to openings for a script/day (day-quality numerator).
     *
     * @return array{available:bool,unique_clients:int,matched_sessions:int,window_sec:int}
     */
    public static function matchedUniqueForScript(int $adminId, string $date, int $scriptId): array
    {
        $empty = ['available' => false, 'unique_clients' => 0, 'matched_sessions' => 0, 'window_sec' => 0];
        if ($adminId < 1 || $scriptId < 1 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return $empty;
        }
        if (!TermScansRepository::tableReady() || !LikespaConfig::flag('term_scans_enabled', true)) {
            return $empty;
        }
        $dayStart = strtotime($date . ' 00:00:00');
        if ($dayStart === false) {
            return $empty;
        }
        $dayEnd = (int)$dayStart + 86399;
        $parts = self::scanPartsForScript($scriptId);
        if ($parts === []) {
            return $empty;
        }
        $windowSec = self::windowSecFromParts($parts);
        $match = self::match($adminId, $scriptId, (int)$dayStart, $dayEnd, $windowSec, $parts);

        return [
            'available' => true,
            'unique_clients' => count($match['clients']),
            'matched_sessions' => count($match['by_collection']),
            'window_sec' => $windowSec,
        ];
    }

    /**
     * Write SCORE_RATE + collections_datas for matched scans in window.
     *
     * @return array{collections:int,clients:int,score_rate_added:int}
     */
    public static function attachForAdmin(int $adminId, int $winStart, int $winEnd): array
    {
        $out = ['collections' => 0, 'clients' => 0, 'score_rate_added' => 0];
        if ($adminId < 1
            || !Database::tableExists('base_records_collections')
            || !Database::tableExists('base_records_collections_datas')
        ) {
            return $out;
        }
        $partsByScript = self::scanPartsByScript();
        if ($partsByScript === []) {
            return $out;
        }

        $pdo = Database::pdo();
        $hasScoreRate = Database::columnExists('base_records_collections', 'SCORE_RATE');
        $clientsAll = [];

        foreach ($partsByScript as $scriptId => $parts) {
            $windowSec = self::windowSecFromParts($parts);
            // Extend fetch so scans shortly after day-end collections still match.
            $scanEnd = $winEnd > 0 ? $winEnd + $windowSec : 0;
            $match = self::match($adminId, $scriptId, $winStart, $winEnd > 0 ? $winEnd : 0, $windowSec, $parts, $scanEnd);
            if ($match['by_collection'] === []) {
                continue;
            }
            $ins = $pdo->prepare(
                'INSERT INTO base_records_collections_datas
                 (DT_START, ID_COLLECTION, ID_PART, ID_INSTANCE, ID_KEY)
                 VALUES (?, ?, ?, 0, 0)'
            );
            $upd = $hasScoreRate
                ? $pdo->prepare('UPDATE base_records_collections SET SCORE_RATE = SCORE_RATE + ? WHERE ID = ?')
                : null;

            foreach ($match['by_collection'] as $colId => $hit) {
                $partId = (int)$hit['part_id'];
                $scanDt = (int)$hit['dt_scan'];
                $rate = (int)$hit['score_rate'];
                // Idempotent: skip if this part already attached to the collection.
                $chk = $pdo->prepare(
                    'SELECT ID FROM base_records_collections_datas
                     WHERE ID_COLLECTION = ? AND ID_PART = ? LIMIT 1'
                );
                $chk->execute([$colId, $partId]);
                if ((int)$chk->fetchColumn() > 0) {
                    continue;
                }
                $ins->execute([$scanDt, $colId, $partId]);
                if ($upd !== null && $rate !== 0) {
                    $upd->execute([$rate, $colId]);
                    $out['score_rate_added'] += $rate;
                }
                $out['collections']++;
                $ck = (string)$hit['client_key'];
                if ($ck !== '') {
                    $clientsAll[$ck] = true;
                }
            }
        }
        $out['clients'] = count($clientsAll);

        return $out;
    }

    /**
     * @param list<array{id:int,score_rate:int,timeout:int,cond_value:int}> $parts
     * @return array{
     *   clients:array<string,true>,
     *   by_collection:array<int,array{part_id:int,dt_scan:int,score_rate:int,client_key:string}>
     * }
     */
    private static function match(
        int $adminId,
        int $scriptId,
        int $winStart,
        int $winEnd,
        int $windowSec,
        array $parts,
        int $scanEndOverride = 0
    ): array {
        $empty = ['clients' => [], 'by_collection' => []];
        if ($adminId < 1 || $scriptId < 1 || $parts === [] || $windowSec < 1) {
            return $empty;
        }
        if (!Database::tableExists('base_records_collections')) {
            return $empty;
        }

        $pdo = Database::pdo();
        $colSql = 'SELECT ID AS id, DT_START AS dt_start, DT_END AS dt_end
                   FROM base_records_collections
                   WHERE ID_ADMIN = ? AND ID_SCRIPT = ?';
        $colParams = [$adminId, $scriptId];
        if ($winStart > 0 && $winEnd >= $winStart) {
            $colSql .= ' AND DT_START <= ? AND (DT_END = 0 OR DT_END >= ?)';
            $colParams[] = $winEnd;
            $colParams[] = $winStart;
        }
        $colSql .= ' ORDER BY DT_START ASC, ID ASC';
        $st = $pdo->prepare($colSql);
        $st->execute($colParams);
        $collections = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($collections === []) {
            // No openings → scans do not count.
            return $empty;
        }

        $scanFrom = $winStart > 0 ? $winStart : 0;
        $scanTo = $scanEndOverride > 0
            ? $scanEndOverride
            : ($winEnd > 0 ? $winEnd + $windowSec : 0);
        if ($scanFrom < 1) {
            $minStart = (int)$collections[0]['dt_start'];
            foreach ($collections as $c) {
                $minStart = min($minStart, (int)$c['dt_start']);
            }
            $scanFrom = max(0, $minStart - 1);
        }
        if ($scanTo < 1) {
            $maxEnd = 0;
            foreach ($collections as $c) {
                $end = (int)$c['dt_end'] > 0 ? (int)$c['dt_end'] : (int)$c['dt_start'];
                $maxEnd = max($maxEnd, $end + $windowSec);
            }
            $scanTo = $maxEnd;
        }

        $stScans = $pdo->prepare(
            'SELECT ID, DT_SCAN, BRACELET_UID, ID_1C, ID_USER, PHONE
             FROM base_term_scans
             WHERE ID_ADMIN = ? AND DT_SCAN >= ? AND DT_SCAN <= ?
             ORDER BY DT_SCAN ASC, ID ASC'
        );
        $stScans->execute([$adminId, $scanFrom, $scanTo]);
        $scans = $stScans->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($scans === []) {
            return $empty;
        }

        // Prefer first configured scan part (usually one per script).
        $part = $parts[0];
        $partId = (int)$part['id'];
        $scoreRate = (int)$part['score_rate'];

        $usedClients = [];
        $byCollection = [];
        $scanIdx = 0;
        $scanN = count($scans);

        foreach ($collections as $col) {
            $colId = (int)$col['id'];
            $dtEnd = (int)$col['dt_end'] > 0 ? (int)$col['dt_end'] : (int)$col['dt_start'];
            $winLo = $dtEnd; // after communication
            $winHi = $dtEnd + $windowSec;
            while ($scanIdx < $scanN && (int)$scans[$scanIdx]['DT_SCAN'] <= $winLo) {
                $scanIdx++;
            }
            $found = null;
            for ($i = $scanIdx; $i < $scanN; $i++) {
                $dt = (int)$scans[$i]['DT_SCAN'];
                if ($dt > $winHi) {
                    break;
                }
                if ($dt <= $winLo) {
                    continue;
                }
                $ck = TermScansRepository::clientKey($scans[$i]);
                if ($ck === '' || isset($usedClients[$ck])) {
                    continue;
                }
                $found = [
                    'part_id' => $partId,
                    'dt_scan' => $dt,
                    'score_rate' => $scoreRate,
                    'client_key' => $ck,
                    'scan_i' => $i,
                ];
                break;
            }
            if ($found === null) {
                continue;
            }
            $usedClients[$found['client_key']] = true;
            $byCollection[$colId] = $found;
            // Advance cursor past this scan so later collections take later scans.
            $scanIdx = (int)$found['scan_i'] + 1;
        }

        return [
            'clients' => $usedClients,
            'by_collection' => $byCollection,
        ];
    }

    /**
     * @return array<int, list<array{id:int,score_rate:int,timeout:int,cond_value:int}>>
     */
    private static function scanPartsByScript(): array
    {
        if (!Database::tableExists('base_records_scripts')
            || !Database::columnExists('base_records_scripts', 'COND_TYPE')
        ) {
            return [];
        }
        $rateCol = Database::columnExists('base_records_scripts', 'SCORE_RATE')
            ? 'SCORE_RATE' : '0';
        $toCol = Database::columnExists('base_records_scripts', 'TIMEOUT')
            ? 'TIMEOUT' : '0';
        $cvCol = Database::columnExists('base_records_scripts', 'COND_VALUE')
            ? 'COND_VALUE' : '0';
        $rows = Database::pdo()->query(
            "SELECT ID AS id, PID AS pid, {$rateCol} AS score_rate,
                    {$toCol} AS timeout, {$cvCol} AS cond_value, COND_TYPE AS cond_type
             FROM base_records_scripts
             WHERE PID > 0 AND DISABLED < 1
               AND (COND_TYPE = 'term_scan_unique' OR COND_TYPE = 'term_scan_conversion')"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $sid = (int)$row['pid'];
            if ($sid < 1) {
                continue;
            }
            if (!isset($out[$sid])) {
                $out[$sid] = [];
            }
            $out[$sid][] = [
                'id' => (int)$row['id'],
                'score_rate' => (int)$row['score_rate'],
                'timeout' => (int)$row['timeout'],
                'cond_value' => (int)$row['cond_value'],
            ];
        }

        return $out;
    }

    /**
     * @return list<array{id:int,score_rate:int,timeout:int,cond_value:int}>
     */
    private static function scanPartsForScript(int $scriptId): array
    {
        $all = self::scanPartsByScript();

        return $all[$scriptId] ?? [];
    }

    /**
     * @param list<array{timeout?:int,cond_value?:int}> $parts
     */
    private static function windowSecFromParts(array $parts): int
    {
        foreach ($parts as $p) {
            $t = (int)($p['timeout'] ?? 0);
            if ($t > 0) {
                return min(86400, $t);
            }
            $c = (int)($p['cond_value'] ?? 0);
            if ($c > 0) {
                return min(86400, $c);
            }
        }

        return self::DEFAULT_WINDOW_SEC;
    }
}
