<?php
declare(strict_types=1);

/**
 * Remigrate skill bans into latest rates row (midnight today):
 * - RESTRICT_TYPES → ban_booking
 * - RESTRICT_TYPES_ONLINE → ban_online
 * - RESTRICT_PROCS → ban_procs (product ids; not whole type)
 * - content[type]=0 (except SPEC/break) → ban_booking + ban_online
 *
 * Content maps come from the latest non-migrator rates row when present.
 *
 * @return array{
 *   ok:bool,
 *   message:string,
 *   operators:int,
 *   types_banned:int,
 *   online_banned:int,
 *   procs_banned:int,
 *   zero_pct_bans:int,
 *   skipped:list<string>
 * }
 */
return static function (PDO $pdo, array $spec): array {
    if (!class_exists(RatesRepository::class, false)) {
        require_once dirname(__DIR__) . '/lib/RatesRepository.php';
    }
    if (!RatesRepository::hasBanColumns()) {
        return [
            'ok' => false,
            'message' => 'ban_booking/ban_online columns missing — run migrate rates first',
            'operators' => 0,
            'types_banned' => 0,
            'online_banned' => 0,
            'procs_banned' => 0,
            'zero_pct_bans' => 0,
            'skipped' => [],
        ];
    }
    if (!RatesRepository::hasBanProcsColumn()) {
        return [
            'ok' => false,
            'message' => 'ban_procs column missing — run migrate rates (004_ban_procs) first',
            'operators' => 0,
            'types_banned' => 0,
            'online_banned' => 0,
            'procs_banned' => 0,
            'zero_pct_bans' => 0,
            'skipped' => [],
        ];
    }

    $skipped = [];
    $opsTouched = 0;
    $typesBanned = 0;
    $onlineBanned = 0;
    $procsBanned = 0;
    $zeroPctBans = 0;

    $hasRestrict = false;
    foreach (['RESTRICT_TYPES', 'RESTRICT_TYPES_ONLINE', 'RESTRICT_PROCS'] as $col) {
        if (Database::columnExists('base_users', $col)) {
            $hasRestrict = true;
            break;
        }
    }

    $cols = 'id';
    foreach (['RESTRICT_TYPES', 'RESTRICT_TYPES_ONLINE', 'RESTRICT_PROCS'] as $col) {
        if (Database::columnExists('base_users', $col)) {
            $cols .= ', ' . $col;
        }
    }
    $users = $pdo->query("SELECT {$cols} FROM base_users")->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $typeIds = class_exists(ProcedureTypesRepository::class, false)
        ? array_keys(ProcedureTypesRepository::names())
        : [];
    $effectiveDt = (int)mktime(0, 0, 0);

    foreach ($users as $u) {
        $oid = (int)($u['id'] ?? 0);
        if ($oid < 1) {
            continue;
        }

        $rt = $hasRestrict
            ? UsersRepository::parseHashIds((string)($u['RESTRICT_TYPES'] ?? ''))
            : [];
        $rto = $hasRestrict
            ? UsersRepository::parseHashIds((string)($u['RESTRICT_TYPES_ONLINE'] ?? ''))
            : [];
        $rp = $hasRestrict
            ? UsersRepository::parseHashIds((string)($u['RESTRICT_PROCS'] ?? ''))
            : [];

        $banBooking = [];
        $banOnline = [];
        $banProcs = [];
        foreach ($rt as $tid) {
            $tid = (int)$tid;
            if ($tid > 0) {
                $banBooking[$tid] = 1;
            }
        }
        foreach ($rto as $tid) {
            $tid = (int)$tid;
            if ($tid > 0) {
                $banOnline[$tid] = 1;
            }
        }
        foreach ($rp as $pid) {
            $pid = (int)$pid;
            if ($pid > 0) {
                $banProcs[$pid] = 1;
            }
        }

        $latest = RatesRepository::latestForOperator($oid, $effectiveDt);
        $base = $latest;
        $listed = RatesRepository::list(['operator_id' => $oid, 'limit' => 30]);
        foreach ($listed['items'] as $item) {
            $descr = (string)($item['descr'] ?? '');
            if (
                strpos($descr, 'restrict→bans') !== false
                || strpos($descr, 'repair bans') !== false
                || strpos($descr, 'remigrate skill bans') !== false
            ) {
                continue;
            }
            $base = $item;
            break;
        }

        if ($base === null && $rt === [] && $rto === [] && $rp === []) {
            continue;
        }

        $content = [];
        if ($base !== null && is_array($base['content'] ?? null)) {
            $content = $base['content'];
        } else {
            foreach ($typeIds as $tid) {
                $content[(int)$tid] = 0.0;
            }
        }

        $beforeBooking = count($banBooking);
        $beforeOnline = count($banOnline);
        $applied = RatesRepository::applyZeroPctTypeBans($content, $banBooking, $banOnline);
        $banBooking = $applied['ban_booking'];
        $banOnline = $applied['ban_online'];
        $zeroPctBans += max(0, count($banBooking) - $beforeBooking)
            + max(0, count($banOnline) - $beforeOnline);

        if ($banBooking === [] && $banOnline === [] && $banProcs === [] && $base === null) {
            continue;
        }

        $sales = ($base !== null && is_array($base['content_sales'] ?? null)) ? $base['content_sales'] : $content;
        $income = ($base !== null && is_array($base['content_income'] ?? null)) ? $base['content_income'] : $content;
        $terminal = ($base !== null && is_array($base['content_terminal'] ?? null)) ? $base['content_terminal'] : $content;

        RatesRepository::create([
            'operator_id' => $oid,
            'dt' => $effectiveDt,
            'descr' => 'remigrate skill bans',
            'content' => $content,
            'content_sales' => $sales,
            'content_income' => $income,
            'content_terminal' => $terminal,
            'ban_booking' => $banBooking,
            'ban_online' => $banOnline,
            'ban_procs' => $banProcs,
            'loss' => (float)($base !== null ? ($base['loss'] ?? 0) : 0),
            'loss_fix' => (float)($base !== null ? ($base['loss_fix'] ?? 0) : 0),
            'offers' => (int)($base !== null ? ($base['offers'] ?? 0) : 0),
            'audio_hour_rate' => (float)($base !== null ? ($base['audio_hour_rate'] ?? 0) : 0),
            'script_score_rate' => (float)($base !== null ? ($base['script_score_rate'] ?? 0) : 0),
        ]);
        $opsTouched++;
        $typesBanned += count($banBooking);
        $onlineBanned += count($banOnline);
        $procsBanned += count($banProcs);
    }

    return [
        'ok' => true,
        'message' => "Remigrated skill bans for {$opsTouched} operators"
            . " (types={$typesBanned}, online={$onlineBanned}, procs={$procsBanned}, zero_pct≈{$zeroPctBans})",
        'operators' => $opsTouched,
        'types_banned' => $typesBanned,
        'online_banned' => $onlineBanned,
        'procs_banned' => $procsBanned,
        'zero_pct_bans' => $zeroPctBans,
        'skipped' => $skipped,
    ];
};
