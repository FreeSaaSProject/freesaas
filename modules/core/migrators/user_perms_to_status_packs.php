<?php
declare(strict_types=1);

/**
 * One-shot: build default screen packs on positions from staff users.
 * - Ordinary roles: majority (≥50%) of *enabled* staff with any perms (not OR —
 *   one outlier must not grant bi.manage to the whole role). Prefer
 *   repair_status_packs_majority if this already ran with OR-union.
 * - Director (SUPERADMIN_ACCESS): all discovered module permissions on
 *
 * Does not rewrite per-user blobs (staff keep their overrides).
 *
 * @return array{
 *   ok:bool,
 *   message:string,
 *   statuses:int,
 *   directors:list<array{id:int,name:string,keys:int}>,
 *   packs:list<array{id:int,name:string,users:int,keys_on:int}>,
 *   skipped:list<string>
 * }
 */
return static function (PDO $pdo, array $spec): array {
    if (!Database::tableExists('base_admin_statuses') || !Database::tableExists('base_users')) {
        return [
            'ok' => false,
            'message' => 'base_admin_statuses / base_users missing',
            'statuses' => 0,
            'directors' => [],
            'packs' => [],
            'skipped' => [],
        ];
    }

    if (!Database::columnExists('base_admin_statuses', 'PERMISSIONS')) {
        $pdo->exec(
            'ALTER TABLE base_admin_statuses
             ADD COLUMN PERMISSIONS MEDIUMTEXT NULL'
        );
    }

    if (!class_exists(StatusesRepository::class, false)) {
        require_once dirname(__DIR__) . '/lib/StatusesRepository.php';
    }
    if (!class_exists(UsersRepository::class, false)) {
        require_once dirname(__DIR__) . '/lib/UsersRepository.php';
    }

    /** @var array<string,true> $catalog */
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
        return [
            'ok' => false,
            'message' => 'No permission keys discovered (enable modules?)',
            'statuses' => 0,
            'directors' => [],
            'packs' => [],
            'skipped' => [],
        ];
    }

    $statusCol = Database::columnExists('base_users', 'ID_STATUS')
        ? 'ID_STATUS'
        : (Database::columnExists('base_users', 'name_enabled') ? 'name_enabled' : '');
    if ($statusCol === '') {
        return [
            'ok' => false,
            'message' => 'No ID_STATUS column on base_users',
            'statuses' => 0,
            'directors' => [],
            'packs' => [],
            'skipped' => [],
        ];
    }

    $hasSuper = Database::columnExists('base_admin_statuses', 'SUPERADMIN_ACCESS');
    $statusSql = 'SELECT ID, NAME'
        . ($hasSuper ? ', SUPERADMIN_ACCESS' : '')
        . ' FROM base_admin_statuses ORDER BY ID';
    $statuses = $pdo->query($statusSql)->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $userSql = "SELECT id, `{$statusCol}` AS status_id, status, enabled FROM base_users";
    $users = $pdo->query($userSql)->fetchAll(PDO::FETCH_ASSOC) ?: [];

    /** @var array<int,list<array<string,mixed>>> $byStatus */
    $byStatus = [];
    foreach ($users as $u) {
        $sid = (int)($u['status_id'] ?? 0);
        if ($sid < 1) {
            continue;
        }
        $byStatus[$sid][] = $u;
    }

    $directors = [];
    $packs = [];
    $skipped = [];
    $updated = 0;

    $upd = $pdo->prepare('UPDATE base_admin_statuses SET PERMISSIONS = ? WHERE ID = ?');

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
            $directors[] = [
                'id' => $sid,
                'name' => $name,
                'keys' => count($pack),
            ];
            $packs[] = [
                'id' => $sid,
                'name' => $name,
                'users' => count($byStatus[$sid] ?? []),
                'keys_on' => count($pack),
                'director' => true,
            ];
            continue;
        }

        $staff = $byStatus[$sid] ?? [];
        $voters = [];
        foreach ($staff as $u) {
            if ((string)($u['enabled'] ?? '1') !== '1' && (int)($u['enabled'] ?? 1) !== 1) {
                continue;
            }
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
            $skipped[] = "status#{$sid} {$name}: no enabled staff with permissions";
            continue;
        }

        $n = count($voters);
        $threshold = (int)ceil($n / 2);
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
        if ($onCount < 1) {
            $skipped[] = "status#{$sid} {$name}: majority empty";
            continue;
        }

        $upd->execute([StatusesRepository::encodePermissions($pack), $sid]);
        $updated++;
        $packs[] = [
            'id' => $sid,
            'name' => $name,
            'users' => $n,
            'keys_on' => $onCount,
            'director' => false,
        ];
    }

    return [
        'ok' => true,
        'message' => "Wrote packs for {$updated} statuses"
            . ($directors !== [] ? ('; directors=' . count($directors) . ' (all modules on)') : '')
            . ($skipped !== [] ? ('; skipped=' . count($skipped)) : ''),
        'statuses' => $updated,
        'directors' => $directors,
        'packs' => $packs,
        'skipped' => $skipped,
        'catalog_keys' => count($catalog),
    ];
};
