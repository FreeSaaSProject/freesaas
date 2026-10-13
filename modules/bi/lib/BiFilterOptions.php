<?php
declare(strict_types=1);

/**
 * Dropdown options for KPI run filters (staff / products).
 */
final class BiFilterOptions
{
    /**
     * @return list<array{id:int,label:string}>
     */
    public static function staffAll(): array
    {
        if (!class_exists('UsersRepository', false) || !Database::tableExists('base_users')) {
            return [];
        }
        $out = [];
        foreach (UsersRepository::listEnabledStaff() as $u) {
            $name = trim((string)($u['name'] ?? ''));
            if ($name === '') {
                $name = (string)($u['login'] ?? '');
            }
            $status = trim((string)($u['status_name'] ?? ''));
            $label = $name;
            if ($status !== '') {
                $label .= ' · ' . $status;
            }
            $out[] = ['id' => (int)$u['id'], 'label' => $label];
        }
        return $out;
    }

    /**
     * Prefer admin status id=2 (likespa legacy); else all staff.
     *
     * @return list<array{id:int,label:string}>
     */
    public static function staffAdmins(): array
    {
        if (!class_exists('UsersRepository', false) || !Database::tableExists('base_users')) {
            return [];
        }
        $admins = [];
        $all = [];
        foreach (UsersRepository::listEnabledStaff() as $u) {
            $name = trim((string)($u['name'] ?? ''));
            if ($name === '') {
                $name = (string)($u['login'] ?? '');
            }
            $row = ['id' => (int)$u['id'], 'label' => $name];
            $all[] = $row;
            if ((int)($u['status_id'] ?? 0) === 2) {
                $admins[] = $row;
            }
        }
        return $admins !== [] ? $admins : $all;
    }

    /**
     * Specialists / operators: non-admin when possible.
     *
     * @return list<array{id:int,label:string}>
     */
    public static function staffOperators(): array
    {
        if (!class_exists('UsersRepository', false) || !Database::tableExists('base_users')) {
            return [];
        }
        $ops = [];
        $all = [];
        foreach (UsersRepository::listEnabledStaff() as $u) {
            $name = trim((string)($u['name'] ?? ''));
            if ($name === '') {
                $name = (string)($u['login'] ?? '');
            }
            $row = ['id' => (int)$u['id'], 'label' => $name];
            $all[] = $row;
            if ((int)($u['status_id'] ?? 0) !== 2) {
                $ops[] = $row;
            }
        }
        return $ops !== [] ? $ops : $all;
    }

    /**
     * Staff for share allowlist: enabled, not IS_HIDDEN (equipment / hidden accounts).
     * ASSIST_TEAM stays visible — admins and assist roles can receive staff shares.
     *
     * @return list<array{id:int,login:string,name:string,status_id:int,status_name:string}>
     */
    public static function shareStaffPicker(): array
    {
        if (!class_exists('UsersRepository', false) || !Database::tableExists('base_users')) {
            return [];
        }
        $staff = UsersRepository::listEnabledStaff();
        if ($staff === []) {
            return [];
        }
        if (!Database::columnExists('base_users', 'IS_HIDDEN')) {
            return $staff;
        }
        $ids = [];
        foreach ($staff as $u) {
            $ids[] = (int)$u['id'];
        }
        $ids = array_values(array_filter($ids, static function (int $id): bool {
            return $id > 0;
        }));
        if ($ids === []) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = Database::pdo()->prepare(
            'SELECT id, COALESCE(IS_HIDDEN, 0) AS is_hidden FROM base_users WHERE id IN (' . $ph . ')'
        );
        $st->execute($ids);
        $hidden = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            if ((int)($row['is_hidden'] ?? 0) !== 0) {
                $hidden[(int)$row['id']] = true;
            }
        }
        $out = [];
        foreach ($staff as $u) {
            $id = (int)$u['id'];
            if (isset($hidden[$id])) {
                continue;
            }
            $out[] = $u;
        }
        return $out;
    }

    /**
     * @return list<array{id:int,label:string}>
     */
    public static function products(): array
    {
        if (!Database::tableExists('base_products')) {
            return [];
        }
        $sql = 'SELECT id, name FROM base_products';
        if (Database::columnExists('base_products', 'enabled')) {
            $sql .= " WHERE enabled = '1'";
        }
        $sql .= ' ORDER BY name ASC, id ASC LIMIT 2000';
        $st = Database::pdo()->query($sql);
        if (!$st) {
            return [];
        }
        $out = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $name = trim((string)($row['name'] ?? ''));
            if ($name === '') {
                $name = '#' . (int)$row['id'];
            }
            $out[] = ['id' => (int)$row['id'], 'label' => $name];
        }
        return $out;
    }

    /**
     * @return array{prev_year:int,prev_month:int,next_year:int,next_month:int}
     */
    public static function adjacentMonth(int $year, int $month): array
    {
        $month = max(1, min(12, $month));
        $prevM = $month - 1;
        $prevY = $year;
        if ($prevM < 1) {
            $prevM = 12;
            $prevY--;
        }
        $nextM = $month + 1;
        $nextY = $year;
        if ($nextM > 12) {
            $nextM = 1;
            $nextY++;
        }
        return [
            'prev_year' => $prevY,
            'prev_month' => $prevM,
            'next_year' => $nextY,
            'next_month' => $nextM,
        ];
    }
}
