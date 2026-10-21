<?php
declare(strict_types=1);

/**
 * Repair: rebuild ban_* from RESTRICT_TYPES / RESTRICT_TYPES_ONLINE only.
 * Does NOT map RESTRICT_PROCS → type bans (that wrongly banned whole MSSG when
 * one product like «Релакс стоп» was restricted).
 *
 * For each operator with type restricts: new midnight rates row = latest non-migrator
 * content maps + bans from RESTRICT_TYPES(+ONLINE). Clears migrator-only type bans
 * that came from PROCS.
 *
 * @return array{ok:bool,message:string,operators:int,cleared_proc_bans:int,skipped:list<string>}
 */
return static function (PDO $pdo, array $spec): array {
    if (!class_exists(RatesRepository::class, false)) {
        require_once dirname(__DIR__) . '/lib/RatesRepository.php';
    }
    if (!RatesRepository::hasBanColumns()) {
        return [
            'ok' => false,
            'message' => 'ban columns missing',
            'operators' => 0,
            'cleared_proc_bans' => 0,
            'skipped' => [],
        ];
    }

    $ops = 0;
    $cleared = 0;
    $skipped = [];
    $effectiveDt = (int)mktime(0, 0, 0);

    $cols = 'id';
    foreach (['RESTRICT_TYPES', 'RESTRICT_TYPES_ONLINE', 'RESTRICT_PROCS'] as $col) {
        if (Database::columnExists('base_users', $col)) {
            $cols .= ', ' . $col;
        }
    }
    $users = $pdo->query("SELECT {$cols} FROM base_users")->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($users as $u) {
        $oid = (int)($u['id'] ?? 0);
        if ($oid < 1) {
            continue;
        }
        $rt = UsersRepository::parseHashIds((string)($u['RESTRICT_TYPES'] ?? ''));
        $rto = UsersRepository::parseHashIds((string)($u['RESTRICT_TYPES_ONLINE'] ?? ''));
        $rp = UsersRepository::parseHashIds((string)($u['RESTRICT_PROCS'] ?? ''));

        $banBooking = [];
        $banOnline = [];
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

        // Detect whether current latest row has type bans that only came from PROCS.
        $latest = RatesRepository::latestForOperator($oid, $effectiveDt);
        $currentBans = is_array($latest['ban_booking'] ?? null) ? $latest['ban_booking'] : [];
        $procTypeIds = [];
        foreach ($rp as $pid) {
            $pid = (int)$pid;
            if ($pid < 1 || !class_exists(ProductsRepository::class, false)) {
                continue;
            }
            $p = ProductsRepository::find($pid);
            $tid = $p ? max(0, (int)($p['type_id'] ?? 0)) : 0;
            if ($tid > 0 && !isset($banBooking[$tid])) {
                $procTypeIds[$tid] = true;
            }
        }

        $needsRewrite = false;
        foreach ($currentBans as $tid => $v) {
            if (empty($v)) {
                continue;
            }
            $tid = (int)$tid;
            if (isset($procTypeIds[$tid]) && !isset($banBooking[$tid])) {
                $needsRewrite = true;
                $cleared++;
            }
        }
        // Also rewrite if bans differ from RESTRICT_TYPES-only set
        $curSet = [];
        foreach ($currentBans as $tid => $v) {
            if (!empty($v)) {
                $curSet[(int)$tid] = true;
            }
        }
        $wantSet = $banBooking;
        if (array_keys($curSet) != array_keys(array_filter($wantSet))
            || count($curSet) !== count(array_filter($wantSet))
        ) {
            // Normalize compare
            $a = array_keys($curSet);
            $b = array_keys(array_filter($wantSet, static fn($v) => !empty($v)));
            sort($a);
            sort($b);
            if ($a !== $b) {
                $needsRewrite = true;
            }
        }
        $curOnline = is_array($latest['ban_online'] ?? null) ? $latest['ban_online'] : [];
        $a = [];
        foreach ($curOnline as $tid => $v) {
            if (!empty($v)) {
                $a[] = (int)$tid;
            }
        }
        $b = array_map('intval', array_keys(array_filter($banOnline)));
        sort($a);
        sort($b);
        if ($a !== $b) {
            $needsRewrite = true;
        }

        if (!$needsRewrite && $rt === [] && $rto === [] && $rp === []) {
            continue;
        }
        if (!$needsRewrite) {
            continue;
        }

        // Prefer content from last non-migrator row
        $base = $latest;
        $listed = RatesRepository::list(['operator_id' => $oid, 'limit' => 20]);
        foreach ($listed['items'] as $item) {
            $descr = (string)($item['descr'] ?? '');
            if (strpos($descr, 'restrict→bans') !== false || strpos($descr, 'repair bans') !== false) {
                continue;
            }
            $base = $item;
            break;
        }
        if ($base === null) {
            $skipped[] = "op#{$oid}: no rates row";
            continue;
        }

        RatesRepository::create([
            'operator_id' => $oid,
            'dt' => $effectiveDt,
            'descr' => 'repair bans from RESTRICT_TYPES',
            'content' => is_array($base['content'] ?? null) ? $base['content'] : [],
            'content_sales' => is_array($base['content_sales'] ?? null) ? $base['content_sales'] : [],
            'content_income' => is_array($base['content_income'] ?? null) ? $base['content_income'] : [],
            'content_terminal' => is_array($base['content_terminal'] ?? null) ? $base['content_terminal'] : [],
            'ban_booking' => $banBooking,
            'ban_online' => $banOnline,
            'loss' => (float)($base['loss'] ?? 0),
            'loss_fix' => (float)($base['loss_fix'] ?? 0),
            'offers' => (int)($base['offers'] ?? 0),
            'audio_hour_rate' => (float)($base['audio_hour_rate'] ?? 0),
            'script_score_rate' => (float)($base['script_score_rate'] ?? 0),
        ]);
        $ops++;
    }

    return [
        'ok' => true,
        'message' => "Repaired bans for {$ops} operators (RESTRICT_TYPES only; cleared PROCS→type over-bans≈{$cleared})",
        'operators' => $ops,
        'cleared_proc_bans' => $cleared,
        'skipped' => $skipped,
    ];
};
