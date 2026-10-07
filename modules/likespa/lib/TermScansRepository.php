<?php
declare(strict_types=1);

/**
 * Terminal scan analytics (oper term_scans.inc.php / show_term_scans).
 */
final class TermScansRepository
{
    public static function tableReady(): bool
    {
        return Database::tableExists('base_term_scans');
    }

    public static function dayStart(int $dt): int
    {
        if ($dt < 1) {
            $dt = time();
        }
        return (int)mktime(0, 0, 0, (int)date('n', $dt), (int)date('j', $dt), (int)date('Y', $dt));
    }

    /** @param array<string,mixed> $row */
    public static function clientKey(array $row): string
    {
        $id1c = trim((string)($row['ID_1C'] ?? $row['id_1c'] ?? ''));
        if ($id1c !== '') {
            return '1c:' . $id1c;
        }
        $idUser = (int)($row['ID_USER'] ?? $row['id_user'] ?? 0);
        if ($idUser > 0) {
            return 'u:' . $idUser;
        }
        $phone = trim((string)($row['PHONE'] ?? $row['phone'] ?? ''));
        if ($phone !== '' && $phone !== '0') {
            return 'p:' . $phone;
        }
        $bracelet = (string)($row['BRACELET_UID'] ?? $row['bracelet_uid'] ?? '');
        $dt = (int)($row['DT_SCAN'] ?? $row['dt_scan'] ?? 0);
        return 'b:' . $bracelet . ':' . $dt;
    }

    /**
     * Per admin × day stats for records calendar conversion.
     *
     * @return array<int, array<string, array{scans:int,unique_clients:int,unique_clients_with_procedures:int}>>
     *   Outer key admin id; inner key Y-m-d date.
     */
    public static function buildAdminDayCache(int $tstart, int $tend, int $procedureWindowHours = 4): array
    {
        if (!self::tableReady() || $tstart < 1 || $tend <= $tstart) {
            return [];
        }
        $procedureWindowHours = max(1, min(24, $procedureWindowHours));
        $procedureWindowSec = $procedureWindowHours * 3600;

        $pdo = Database::pdo();
        $st = $pdo->prepare(
            'SELECT ID_ADMIN, DT_SCAN, BRACELET_UID, ID_1C, ID_USER, PHONE
             FROM base_term_scans
             WHERE DT_SCAN >= ? AND DT_SCAN < ?
             ORDER BY DT_SCAN ASC'
        );
        $st->execute([$tstart, $tend]);
        $scanRows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($scanRows === []) {
            return [];
        }

        $bracelets = [];
        $minScan = 0;
        $maxScan = 0;
        foreach ($scanRows as $row) {
            $dt = (int)($row['DT_SCAN'] ?? 0);
            if ($dt > 0) {
                if ($minScan === 0 || $dt < $minScan) {
                    $minScan = $dt;
                }
                if ($dt > $maxScan) {
                    $maxScan = $dt;
                }
            }
            $b = trim((string)($row['BRACELET_UID'] ?? ''));
            if ($b !== '') {
                $bracelets[$b] = true;
            }
        }

        $proceduresByBracelet = self::proceduresByBracelet(
            array_keys($bracelets),
            $minScan,
            $maxScan,
            $procedureWindowSec
        );

        /** @var array<int, array<string, array{scans:int,unique_clients:array<string,true>,unique_clients_with_procedures:array<string,true>}>> $cache */
        $cache = [];
        foreach ($scanRows as $row) {
            $adminId = (int)($row['ID_ADMIN'] ?? 0);
            $scanDt = (int)($row['DT_SCAN'] ?? 0);
            if ($adminId < 1 || $scanDt < 1) {
                continue;
            }
            $day = date('Y-m-d', $scanDt);
            $clientKey = self::clientKey($row);
            $bracelet = trim((string)($row['BRACELET_UID'] ?? ''));

            if (!isset($cache[$adminId][$day])) {
                $cache[$adminId][$day] = [
                    'scans' => 0,
                    'unique_clients' => [],
                    'unique_clients_with_procedures' => [],
                ];
            }
            $cache[$adminId][$day]['scans']++;
            $cache[$adminId][$day]['unique_clients'][$clientKey] = true;

            if ($bracelet !== '' && isset($proceduresByBracelet[$bracelet])) {
                foreach ($proceduresByBracelet[$bracelet] as $procStart) {
                    if ($procStart >= $scanDt && $procStart <= ($scanDt + $procedureWindowSec)) {
                        $cache[$adminId][$day]['unique_clients_with_procedures'][$clientKey] = true;
                        break;
                    }
                }
            }
        }

        $out = [];
        foreach ($cache as $adminId => $days) {
            foreach ($days as $day => $data) {
                $out[$adminId][$day] = [
                    'scans' => (int)$data['scans'],
                    'unique_clients' => count($data['unique_clients']),
                    'unique_clients_with_procedures' => count($data['unique_clients_with_procedures']),
                ];
            }
        }
        return $out;
    }

    /**
     * Dashboard stats for /likespa/term-scans.
     *
     * @return array{
     *   admins: array<int, array{
     *     scans:int,unique_bracelets:int,unique_clients:int,unique_clients_with_procedures:int,
     *     promoter_scans:int,promoter_unique_clients:int,discount_activations:int,
     *     session_groups:int,session_group_sizes:list<int>
     *   }>,
     *   totals: array{
     *     scans:int,unique_bracelets:int,unique_clients:int,unique_clients_with_procedures:int,
     *     promoter_scans:int,promoter_unique_clients:int,discount_activations:int,
     *     session_groups:int,session_group_sizes:list<int>
     *   },
     *   procedure_window_hours:int,session_gap_minutes:int
     * }
     */
    public static function buildStats(
        int $tstart,
        int $tend,
        int $idAdminFilter = 0,
        int $procedureWindowHours = 4,
        int $sessionGapMinutes = 15
    ): array {
        $emptyTotals = [
            'scans' => 0,
            'unique_bracelets' => 0,
            'unique_clients' => 0,
            'unique_clients_with_procedures' => 0,
            'promoter_scans' => 0,
            'promoter_unique_clients' => 0,
            'discount_activations' => 0,
            'session_groups' => 0,
            'session_group_sizes' => [],
        ];
        $stats = [
            'admins' => [],
            'totals' => $emptyTotals,
            'procedure_window_hours' => max(1, min(24, $procedureWindowHours)),
            'session_gap_minutes' => max(1, min(120, $sessionGapMinutes)),
        ];
        if (!self::tableReady() || $tstart < 1 || $tend <= $tstart) {
            return $stats;
        }

        $procedureWindowSec = $stats['procedure_window_hours'] * 3600;
        $sessionGapSec = $stats['session_gap_minutes'] * 60;

        $pdo = Database::pdo();
        $sql = 'SELECT * FROM base_term_scans WHERE DT_SCAN >= ? AND DT_SCAN < ?';
        $params = [$tstart, $tend];
        if ($idAdminFilter > 0) {
            $sql .= ' AND ID_ADMIN = ?';
            $params[] = $idAdminFilter;
        }
        $sql .= ' ORDER BY DT_SCAN ASC';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $scanRows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($scanRows === []) {
            return $stats;
        }

        $bracelets = [];
        $minScan = 0;
        $maxScan = 0;
        foreach ($scanRows as $row) {
            $dt = (int)($row['DT_SCAN'] ?? 0);
            if ($dt > 0) {
                if ($minScan === 0 || $dt < $minScan) {
                    $minScan = $dt;
                }
                if ($dt > $maxScan) {
                    $maxScan = $dt;
                }
            }
            $b = trim((string)($row['BRACELET_UID'] ?? ''));
            if ($b !== '') {
                $bracelets[$b] = true;
            }
        }
        $proceduresByBracelet = self::proceduresByBracelet(
            array_keys($bracelets),
            $minScan,
            $maxScan,
            $procedureWindowSec
        );

        $allBracelets = [];
        $allClients = [];
        $allWithProc = [];
        $allPromoClients = [];
        $activatedClients = [];

        foreach ($scanRows as $row) {
            $adminId = (int)($row['ID_ADMIN'] ?? 0);
            if ($adminId < 1) {
                continue;
            }
            if (!isset($stats['admins'][$adminId])) {
                $stats['admins'][$adminId] = [
                    'scans' => 0,
                    'unique_bracelets' => [],
                    'unique_clients' => [],
                    'unique_clients_with_procedures' => [],
                    'promoter_scans' => 0,
                    'promoter_unique_clients' => [],
                    'discount_activations' => 0,
                    'session_group_sizes' => [],
                    '_session_last_dt' => 0,
                    '_session_current_size' => 0,
                ];
            }

            $clientKey = self::clientKey($row);
            $bracelet = trim((string)($row['BRACELET_UID'] ?? ''));
            $scanDt = (int)($row['DT_SCAN'] ?? 0);

            $stats['admins'][$adminId]['scans']++;
            $stats['totals']['scans']++;

            if ($bracelet !== '') {
                $stats['admins'][$adminId]['unique_bracelets'][$bracelet] = true;
                $allBracelets[$bracelet] = true;
            }
            $stats['admins'][$adminId]['unique_clients'][$clientKey] = true;
            $allClients[$clientKey] = true;

            $hasProc = false;
            if ($bracelet !== '' && $scanDt > 0 && isset($proceduresByBracelet[$bracelet])) {
                foreach ($proceduresByBracelet[$bracelet] as $procStart) {
                    if ($procStart >= $scanDt && $procStart <= ($scanDt + $procedureWindowSec)) {
                        $hasProc = true;
                        break;
                    }
                }
            }
            if ($hasProc) {
                $stats['admins'][$adminId]['unique_clients_with_procedures'][$clientKey] = true;
                $allWithProc[$clientKey] = true;
            }

            if (!empty($row['IS_PROMOTER'])) {
                $stats['admins'][$adminId]['promoter_scans']++;
                $stats['totals']['promoter_scans']++;
                $stats['admins'][$adminId]['promoter_unique_clients'][$clientKey] = true;
                $allPromoClients[$clientKey] = true;
            }

            if (!empty($row['DISCOUNT_ACTIVATED']) && !isset($activatedClients[$clientKey])) {
                $activatedClients[$clientKey] = true;
                $stats['admins'][$adminId]['discount_activations']++;
                $stats['totals']['discount_activations']++;
            }

            if ($scanDt > 0) {
                $lastDt = (int)$stats['admins'][$adminId]['_session_last_dt'];
                $curSize = (int)$stats['admins'][$adminId]['_session_current_size'];
                if ($curSize > 0 && $lastDt > 0 && (($scanDt - $lastDt) > $sessionGapSec)) {
                    $stats['admins'][$adminId]['session_group_sizes'][] = $curSize;
                    $curSize = 0;
                }
                $curSize++;
                $stats['admins'][$adminId]['_session_current_size'] = $curSize;
                $stats['admins'][$adminId]['_session_last_dt'] = $scanDt;
            }
        }

        $stats['totals']['unique_bracelets'] = count($allBracelets);
        $stats['totals']['unique_clients'] = count($allClients);
        $stats['totals']['unique_clients_with_procedures'] = count($allWithProc);
        $stats['totals']['promoter_unique_clients'] = count($allPromoClients);

        $allGroupSizes = [];
        foreach ($stats['admins'] as $adminId => $adminData) {
            if ((int)$adminData['_session_current_size'] > 0) {
                $stats['admins'][$adminId]['session_group_sizes'][] = (int)$adminData['_session_current_size'];
            }
            unset($stats['admins'][$adminId]['_session_last_dt'], $stats['admins'][$adminId]['_session_current_size']);

            $groupSizes = $stats['admins'][$adminId]['session_group_sizes'];
            $stats['admins'][$adminId]['session_groups'] = count($groupSizes);
            $stats['admins'][$adminId]['unique_bracelets'] = count($adminData['unique_bracelets']);
            $stats['admins'][$adminId]['unique_clients'] = count($adminData['unique_clients']);
            $stats['admins'][$adminId]['unique_clients_with_procedures'] = count($adminData['unique_clients_with_procedures']);
            $stats['admins'][$adminId]['promoter_unique_clients'] = count($adminData['promoter_unique_clients']);

            foreach ($groupSizes as $size) {
                $allGroupSizes[] = (int)$size;
            }
        }
        $stats['totals']['session_group_sizes'] = $allGroupSizes;
        $stats['totals']['session_groups'] = count($allGroupSizes);
        ksort($stats['admins']);

        return $stats;
    }

    /**
     * @param list<int> $sizes
     * @return array{text:string,histogram:string}
     */
    public static function formatSessionGroups(array $sizes): array
    {
        $sizes = array_map('intval', $sizes);
        if ($sizes === []) {
            return ['text' => '—', 'histogram' => ''];
        }
        $text = implode('+', $sizes);
        $hist = [];
        foreach ($sizes as $size) {
            if (!isset($hist[$size])) {
                $hist[$size] = 0;
            }
            $hist[$size]++;
        }
        ksort($hist);
        $parts = [];
        foreach ($hist as $size => $count) {
            $parts[] = t('likespa.term_scans.session_hist_item', [
                'size' => (string)$size,
                'count' => (string)$count,
            ]);
        }
        return ['text' => $text, 'histogram' => implode(', ', $parts)];
    }

    /**
     * Detail journal rows for period [tstart, tend).
     *
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public static function listForPeriod(
        int $tstart,
        int $tend,
        int $idAdminFilter = 0,
        int $limit = 50,
        int $offset = 0
    ): array {
        if (!self::tableReady() || $tstart < 1 || $tend <= $tstart) {
            return ['items' => [], 'total' => 0];
        }
        $pdo = Database::pdo();
        $where = 'DT_SCAN >= ? AND DT_SCAN < ?';
        $params = [$tstart, $tend];
        if ($idAdminFilter > 0) {
            $where .= ' AND ID_ADMIN = ?';
            $params[] = $idAdminFilter;
        }
        $stCount = $pdo->prepare("SELECT COUNT(*) FROM base_term_scans WHERE {$where}");
        $stCount->execute($params);
        $total = (int)$stCount->fetchColumn();
        if ($total < 1) {
            return ['items' => [], 'total' => 0];
        }
        $limit = (int)$limit;
        $offset = max(0, (int)$offset);
        $limitSql = '';
        if ($limit > 0) {
            $limit = max(1, min(3000, $limit));
            $limitSql = " LIMIT {$limit} OFFSET {$offset}";
        }
        $st = $pdo->prepare(
            "SELECT ID AS id, DT_SCAN AS dt_scan, SCAN_DAY AS scan_day, ID_ADMIN AS id_admin,
                    ID_TERMINAL AS id_terminal, BRACELET_UID AS bracelet_uid, ID_1C AS id_1c,
                    ID_USER AS id_user, PHONE AS phone, PHONE_1C AS phone_1c,
                    IS_PROMOTER AS is_promoter, DISCOUNT_ACTIVATED AS discount_activated,
                    ID_PROMOTER AS id_promoter
             FROM base_term_scans
             WHERE {$where}
             ORDER BY DT_SCAN DESC, ID DESC
             {$limitSql}"
        );
        $st->execute($params);
        $items = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return ['items' => $items, 'total' => $total];
    }

    /**
     * Enrich scan rows with client name/phone and procedure counts in window.
     *
     * @param list<array<string,mixed>> $scanRows
     * @return array<int, array{id_user:int,name:string,tel:string,phone_1c:string,proc_count:int,proc_dt:int,source:string}>
     */
    public static function enrichFromProcedures(array $scanRows, int $procedureWindowHours = 4): array
    {
        $result = [];
        if ($scanRows === []) {
            return $result;
        }
        $procedureWindowHours = max(1, min(24, $procedureWindowHours));
        $procedureWindowSec = $procedureWindowHours * 3600;

        $bracelets = [];
        $minScanDt = 0;
        $maxScanDt = 0;
        $userIdsDirect = [];

        foreach ($scanRows as $row) {
            $scanId = (int)($row['id'] ?? $row['ID'] ?? 0);
            if ($scanId < 1) {
                continue;
            }
            $result[$scanId] = [
                'id_user' => 0,
                'name' => '',
                'tel' => '',
                'phone_1c' => '',
                'proc_count' => 0,
                'proc_dt' => 0,
                'source' => '',
            ];

            $idUser = (int)($row['id_user'] ?? $row['ID_USER'] ?? 0);
            $phone = trim((string)($row['phone'] ?? $row['PHONE'] ?? ''));
            $phone1c = trim((string)($row['phone_1c'] ?? $row['PHONE_1C'] ?? ''));
            if ($idUser > 1) {
                $result[$scanId]['id_user'] = $idUser;
                $result[$scanId]['source'] = 'scan';
                $userIdsDirect[$idUser] = $idUser;
            }
            if ($phone !== '' && $phone !== '0') {
                $result[$scanId]['tel'] = $phone;
            }
            if ($phone1c !== '' && $phone1c !== '0') {
                $result[$scanId]['phone_1c'] = $phone1c;
            }

            $bracelet = trim((string)($row['bracelet_uid'] ?? $row['BRACELET_UID'] ?? ''));
            $scanDt = (int)($row['dt_scan'] ?? $row['DT_SCAN'] ?? 0);
            if ($bracelet !== '') {
                $bracelets[$bracelet] = true;
            }
            if ($scanDt > 0) {
                if ($minScanDt === 0 || $scanDt < $minScanDt) {
                    $minScanDt = $scanDt;
                }
                if ($scanDt > $maxScanDt) {
                    $maxScanDt = $scanDt;
                }
            }
        }

        /** @var array<string, list<array{id_user:int,dtstart:int}>> $proceduresByBracelet */
        $proceduresByBracelet = [];
        if ($bracelets !== [] && $minScanDt > 0 && $maxScanDt > 0
            && Database::tableExists('base_procedures')
            && Database::columnExists('base_procedures', '1C_BRACELET')
        ) {
            $pdo = Database::pdo();
            $keys = array_keys($bracelets);
            $placeholders = implode(',', array_fill(0, count($keys), '?'));
            $params = $keys;
            $params[] = $minScanDt;
            $params[] = $maxScanDt + $procedureWindowSec;
            // ID_USER>1: skip service client; ID_GOOD!=16: skip chill pause (oper parity).
            $st = $pdo->prepare(
                "SELECT ID_USER AS id_user, `1C_BRACELET` AS bracelet, DTSTART AS dtstart
                 FROM base_procedures
                 WHERE ENABLED = 1 AND ID_USER > 1 AND ID_GOOD <> 16
                   AND `1C_BRACELET` IN ($placeholders)
                   AND DTSTART >= ? AND DTSTART <= ?
                 ORDER BY DTSTART ASC"
            );
            $st->execute($params);
            while ($proc = $st->fetch(PDO::FETCH_ASSOC)) {
                $b = trim((string)($proc['bracelet'] ?? ''));
                if ($b === '') {
                    continue;
                }
                $proceduresByBracelet[$b][] = [
                    'id_user' => (int)($proc['id_user'] ?? 0),
                    'dtstart' => (int)($proc['dtstart'] ?? 0),
                ];
            }
        }

        $userIdsNeeded = $userIdsDirect;
        foreach ($scanRows as $row) {
            $scanId = (int)($row['id'] ?? $row['ID'] ?? 0);
            if ($scanId < 1 || !isset($result[$scanId])) {
                continue;
            }
            $bracelet = trim((string)($row['bracelet_uid'] ?? $row['BRACELET_UID'] ?? ''));
            $scanDt = (int)($row['dt_scan'] ?? $row['DT_SCAN'] ?? 0);
            if ($bracelet === '' || $scanDt < 1 || !isset($proceduresByBracelet[$bracelet])) {
                continue;
            }
            $matched = [];
            $firstProcDt = 0;
            foreach ($proceduresByBracelet[$bracelet] as $proc) {
                if ($proc['dtstart'] < $scanDt || $proc['dtstart'] > ($scanDt + $procedureWindowSec)) {
                    continue;
                }
                $matched[] = $proc;
                if ($firstProcDt === 0 || $proc['dtstart'] < $firstProcDt) {
                    $firstProcDt = $proc['dtstart'];
                }
            }
            if ($matched === []) {
                continue;
            }
            $userCounts = [];
            foreach ($matched as $proc) {
                $uid = $proc['id_user'];
                if (!isset($userCounts[$uid])) {
                    $userCounts[$uid] = 0;
                }
                $userCounts[$uid]++;
            }
            arsort($userCounts);
            $bestUserId = 0;
            foreach ($userCounts as $uid => $_) {
                $bestUserId = (int)$uid;
                break;
            }
            $result[$scanId]['proc_count'] = count($matched);
            $result[$scanId]['proc_dt'] = $firstProcDt;
            if ($result[$scanId]['id_user'] <= 1) {
                $result[$scanId]['id_user'] = $bestUserId;
                $result[$scanId]['source'] = 'procedure';
            } elseif ($result[$scanId]['source'] === 'scan') {
                $result[$scanId]['source'] = 'scan+procedure';
            }
            if ($bestUserId > 1) {
                $userIdsNeeded[$bestUserId] = $bestUserId;
            }
        }

        if ($userIdsNeeded !== [] && Database::tableExists('base_clients')) {
            $ids = array_values($userIdsNeeded);
            $in = implode(',', array_map('intval', $ids));
            $st = Database::pdo()->query(
                "SELECT id, name, tel FROM base_clients WHERE id IN ($in)"
            );
            $users = [];
            if ($st) {
                while ($u = $st->fetch(PDO::FETCH_ASSOC)) {
                    $uid = (int)($u['id'] ?? 0);
                    $users[$uid] = [
                        'name' => trim((string)($u['name'] ?? '')),
                        'tel' => trim((string)($u['tel'] ?? '')),
                    ];
                }
            }
            foreach ($result as $scanId => $info) {
                $uid = (int)$info['id_user'];
                if ($uid > 1 && isset($users[$uid])) {
                    if ($result[$scanId]['name'] === '') {
                        $result[$scanId]['name'] = $users[$uid]['name'];
                    }
                    if ($result[$scanId]['tel'] === '' && $users[$uid]['tel'] !== '') {
                        $result[$scanId]['tel'] = $users[$uid]['tel'];
                    }
                }
            }
        }

        // Fallback: PHONE_1C → base_clients.tel
        $phonesToFind = [];
        foreach ($result as $info) {
            if ((int)$info['id_user'] > 1) {
                continue;
            }
            $phone1c = trim((string)$info['phone_1c']);
            if ($phone1c !== '' && $phone1c !== '0') {
                $phonesToFind[$phone1c] = $phone1c;
            }
        }
        if ($phonesToFind !== [] && Database::tableExists('base_clients')) {
            $pdo = Database::pdo();
            $keys = array_values($phonesToFind);
            $placeholders = implode(',', array_fill(0, count($keys), '?'));
            $st = $pdo->prepare(
                "SELECT id, name, tel FROM base_clients WHERE tel IN ($placeholders)"
            );
            $st->execute($keys);
            $usersByPhone = [];
            while ($u = $st->fetch(PDO::FETCH_ASSOC)) {
                $tel = trim((string)($u['tel'] ?? ''));
                if ($tel === '' || isset($usersByPhone[$tel])) {
                    continue;
                }
                $usersByPhone[$tel] = [
                    'id_user' => (int)($u['id'] ?? 0),
                    'name' => trim((string)($u['name'] ?? '')),
                    'tel' => $tel,
                ];
            }
            foreach ($result as $scanId => $info) {
                if ((int)$info['id_user'] > 1) {
                    continue;
                }
                $phone1c = trim((string)$info['phone_1c']);
                if ($phone1c === '' || !isset($usersByPhone[$phone1c])) {
                    continue;
                }
                $found = $usersByPhone[$phone1c];
                $result[$scanId]['id_user'] = $found['id_user'];
                $result[$scanId]['name'] = $found['name'];
                if ($result[$scanId]['tel'] === '') {
                    $result[$scanId]['tel'] = $found['tel'];
                }
                $result[$scanId]['source'] = 'shopusers';
            }
        }

        return $result;
    }

    /**
     * @param list<string> $bracelets
     * @return array<string, list<int>>
     */
    private static function proceduresByBracelet(
        array $bracelets,
        int $minScan,
        int $maxScan,
        int $procedureWindowSec
    ): array {
        if ($bracelets === [] || $minScan < 1 || $maxScan < 1
            || !Database::tableExists('base_procedures')
            || !Database::columnExists('base_procedures', '1C_BRACELET')
        ) {
            return [];
        }
        $pdo = Database::pdo();
        $placeholders = implode(',', array_fill(0, count($bracelets), '?'));
        $params = $bracelets;
        $params[] = $minScan - $procedureWindowSec;
        $params[] = $maxScan + $procedureWindowSec;
        $st = $pdo->prepare(
            "SELECT `1C_BRACELET` AS bracelet, DTSTART AS dtstart
             FROM base_procedures
             WHERE ENABLED = 1 AND `1C_BRACELET` IN ($placeholders)
               AND DTSTART >= ? AND DTSTART <= ?"
        );
        $st->execute($params);
        $out = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $b = trim((string)($row['bracelet'] ?? ''));
            if ($b === '') {
                continue;
            }
            $out[$b][] = (int)($row['dtstart'] ?? 0);
        }
        return $out;
    }
}
