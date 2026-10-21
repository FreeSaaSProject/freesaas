<?php
declare(strict_types=1);

/**
 * Repair role screen packs: majority vote of *enabled* staff on that role
 * (not OR-union — one masseur with bi.manage polluted the whole role).
 * Director (SUPERADMIN_ACCESS): still all module permissions on.
 *
 * @return array{ok:bool,message:string,statuses:int,packs:list<array<string,mixed>>,skipped:list<string>}
 */
return static function (PDO $pdo, array $spec): array {
    if (!Database::tableExists('base_admin_statuses') || !Database::tableExists('base_users')) {
        return ['ok' => false, 'message' => 'tables missing', 'statuses' => 0, 'packs' => [], 'skipped' => []];
    }
    if (!Database::columnExists('base_admin_statuses', 'PERMISSIONS')) {
        return ['ok' => false, 'message' => 'PERMISSIONS column missing', 'statuses' => 0, 'packs' => [], 'skipped' => []];
    }
    if (!class_exists(StatusesRepository::class, false)) {
        require_once dirname(__DIR__) . '/lib/StatusesRepository.php';
    }
    if (!class_exists(UsersRepository::class, false)) {
        require_once dirname(__DIR__) . '/lib/UsersRepository.php';
    }

    $catalog = [];
    foreach (UsersRepository::availablePermissionKeys() as $group) {
        foreach ($group['keys'] as $key) {
            $pid = is_array($key) ? (string)$key['id'] : (string)$key;
            if ($pid !== '') {
                $catalog[$pid] = true;
            }
        }
    }
    if ($catalog === []) {
        return ['ok' => false, 'message' => 'empty permission catalog', 'statuses' => 0, 'packs' => [], 'skipped' => []];
    }

    $statusCol = Database::columnExists('base_users', 'ID_STATUS') ? 'ID_STATUS' : '';
    if ($statusCol === '') {
        return ['ok' => false, 'message' => 'no ID_STATUS', 'statuses' => 0, 'packs' => [], 'skipped' => []];
    }

    $hasSuper = Database::columnExists('base_admin_statuses', 'SUPERADMIN_ACCESS');
    $statuses = $pdo->query(
        'SELECT ID, NAME' . ($hasSuper ? ', SUPERADMIN_ACCESS' : '') . ' FROM base_admin_statuses ORDER BY ID'
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $users = $pdo->query(
        "SELECT id, name, `{$statusCol}` AS status_id, status, enabled FROM base_users"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $byStatus = [];
    foreach ($users as $u) {
        $sid = (int)($u['status_id'] ?? 0);
        if ($sid < 1) {
            continue;
        }
        // Enabled only — trainees / odd accounts still count if enabled=1
        if ((string)($u['enabled'] ?? '1') !== '1' && (int)($u['enabled'] ?? 1) !== 1) {
            continue;
        }
        $byStatus[$sid][] = $u;
    }

    $upd = $pdo->prepare('UPDATE base_admin_statuses SET PERMISSIONS = ? WHERE ID = ?');
    $packs = [];
    $skipped = [];
    $updated = 0;

    foreach ($statuses as $st) {
        $sid = (int)($st['ID'] ?? 0);
        if ($sid < 1) {
            continue;
        }
        $name = trim((string)($st['NAME'] ?? ('#' . $sid)));
        $isDirector = $hasSuper && (int)($st['SUPERADMIN_ACCESS'] ?? 0) > 0;

        if ($isDirector) {
            $pack = [];
            foreach (array_keys($catalog) as $pid) {
                $pack[$pid] = 1;
            }
            $upd->execute([StatusesRepository::encodePermissions($pack), $sid]);
            $updated++;
            $packs[] = ['id' => $sid, 'name' => $name, 'mode' => 'director_all', 'keys_on' => count($pack), 'voters' => count($byStatus[$sid] ?? [])];
            continue;
        }

        $staff = $byStatus[$sid] ?? [];
        $voters = [];
        foreach ($staff as $u) {
            $perms = UsersRepository::decodePermissions($u['status'] ?? null);
            $any = false;
            foreach ($perms as $v) {
                if (!empty($v)) {
                    $any = true;
                    break;
                }
            }
            if (!$any) {
                continue;
            }
            $voters[] = $perms;
        }
        if ($voters === []) {
            $skipped[] = "status#{$sid} {$name}: no enabled staff with perms";
            continue;
        }

        $n = count($voters);
        $threshold = (int)ceil($n / 2); // majority (≥50% rounded up)
        $counts = [];
        foreach (array_keys($catalog) as $pid) {
            $counts[$pid] = 0;
        }
        foreach ($voters as $perms) {
            foreach ($perms as $pid => $on) {
                $pid = (string)$pid;
                if (!isset($catalog[$pid]) || empty($on)) {
                    continue;
                }
                $counts[$pid]++;
            }
        }

        $pack = [];
        $onCount = 0;
        foreach ($counts as $pid => $c) {
            $on = $c >= $threshold ? 1 : 0;
            $pack[$pid] = $on;
            if ($on) {
                $onCount++;
            }
        }
        $upd->execute([StatusesRepository::encodePermissions($pack), $sid]);
        $updated++;
        $packs[] = [
            'id' => $sid,
            'name' => $name,
            'mode' => 'majority',
            'keys_on' => $onCount,
            'voters' => $n,
            'threshold' => $threshold,
        ];
    }

    return [
        'ok' => true,
        'message' => "Repaired {$updated} status packs (majority of enabled staff; director=all)",
        'statuses' => $updated,
        'packs' => $packs,
        'skipped' => $skipped,
        'catalog_keys' => count($catalog),
    ];
};
