<?php
declare(strict_types=1);

return static function (PDO $pdo, array $spec): array {
    $tableExists = static function (PDO $pdo, string $table): bool {
        $st = $pdo->prepare(
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
        );
        $st->execute([$table]);
        return (bool)$st->fetchColumn();
    };

    $src = 'phpshop_shopusers';
    $dst = 'base_clients';
    if (!$tableExists($pdo, $src)) {
        throw new RuntimeException("Source table missing: $src");
    }
    if (!$tableExists($pdo, $dst)) {
        throw new RuntimeException("Target table missing: $dst (run schema migrate first)");
    }

    $nullable = static function ($v): ?string {
        if ($v === null) {
            return null;
        }
        $s = trim((string)$v);
        return $s === '' ? null : $s;
    };

    $notesJoin = static function (...$parts): ?string {
        $out = [];
        foreach ($parts as $p) {
            $s = trim((string)($p ?? ''));
            if ($s !== '') {
                $out[] = $s;
            }
        }
        if (!$out) {
            return null;
        }
        return implode("\n", $out);
    };

    $birthdayFromInn = static function ($inn): ?string {
        $ts = (int)$inn;
        if ($ts <= 0) {
            return null;
        }
        // Local TZ (Config timezone already applied in bootstrap for CLI migrate).
        $d = date('Y-m-d', $ts);
        $y = (int)substr($d, 0, 4);
        if ($y < 1920 || $y > (int)date('Y')) {
            return null;
        }
        return $d;
    };

    $registeredAt = static function ($datas): ?int {
        if ($datas === null || $datas === '') {
            return null;
        }
        if (is_numeric($datas)) {
            $n = (int)$datas;
            return $n > 0 ? $n : null;
        }
        $ts = strtotime((string)$datas);
        return ($ts !== false && $ts > 0) ? $ts : null;
    };

    /** Display name: legacy name → passport FIO → phone (login). */
    $resolveName = static function (array $r) use ($nullable): string {
        $name = trim((string)($r['name'] ?? ''));
        if ($name !== '') {
            return $name;
        }
        $fio = trim(implode(' ', array_filter([
            $nullable($r['SURNAME'] ?? null),
            $nullable($r['PNAME'] ?? null),
            $nullable($r['PATRONYMIC'] ?? null),
        ])));
        if ($fio !== '') {
            return $fio;
        }
        $tel = trim((string)($r['tel'] ?? ''));
        return $tel !== '' ? $tel : ('#' . (int)($r['id'] ?? 0));
    };

    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    $pdo->exec('TRUNCATE TABLE `base_clients`');

    $st = $pdo->query(
        'SELECT id, name, tel, mail, password, inn, kpp, enabled, datas, status, ID_USER,
                SURNAME, PNAME, PATRONYMIC,
                PASSPORT_SERIE, PASSPORT_NUMBER, PASSPORT_VIDAN, PASSPORT_DATA_VID, PASSPORT_BIRTHDAY,
                ADDRESS_PASSPORT, ADDRESS_FACT,
                subscribe, adres, ADD_INFO, ADMIN_NOTICES
         FROM `phpshop_shopusers`'
    );

    $ins = $pdo->prepare(
        'INSERT INTO `base_clients` (
            id, name, tel, mail, password, birthday, gender, enabled, registered_at, status_id,
            staff_user_id, surname, pname, patronymic,
            passport_serie, passport_number, passport_issued_by, passport_issued_at, passport_birthday,
            address_passport, address_fact, subscribe, notes
        ) VALUES (
            ?,?,?,?,?,?,?,?,?,?,
            ?,?,?,?,
            ?,?,?,?,?,
            ?,?,?,?
        )'
    );

    $copied = 0;
    $namedFromFallback = 0;
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $genderRaw = trim((string)($r['kpp'] ?? ''));
        $gender = null;
        if ($genderRaw === '0' || $genderRaw === '1') {
            $gender = (int)$genderRaw;
        }

        $staff = (int)($r['ID_USER'] ?? 0);
        $staffUserId = $staff > 0 ? $staff : null;

        $enabled = ((string)($r['enabled'] ?? '0') === '1') ? 1 : 0;
        $subscribe = !empty($r['subscribe']) ? 1 : 0;

        $rawName = trim((string)($r['name'] ?? ''));
        $name = $resolveName($r);
        if ($rawName === '' && $name !== '') {
            $namedFromFallback++;
        }

        $ins->execute([
            (int)$r['id'],
            $name,
            (string)($r['tel'] ?? ''),
            (string)($r['mail'] ?? ''),
            (string)($r['password'] ?? ''),
            $birthdayFromInn($r['inn'] ?? 0),
            $gender,
            $enabled,
            $registeredAt($r['datas'] ?? null),
            (int)($r['status'] ?? 0),
            $staffUserId,
            $nullable($r['SURNAME'] ?? null),
            $nullable($r['PNAME'] ?? null),
            $nullable($r['PATRONYMIC'] ?? null),
            $nullable($r['PASSPORT_SERIE'] ?? null),
            $nullable($r['PASSPORT_NUMBER'] ?? null),
            $nullable($r['PASSPORT_VIDAN'] ?? null),
            $nullable($r['PASSPORT_DATA_VID'] ?? null),
            $nullable($r['PASSPORT_BIRTHDAY'] ?? null),
            $nullable($r['ADDRESS_PASSPORT'] ?? null),
            $nullable($r['ADDRESS_FACT'] ?? null),
            $subscribe,
            $notesJoin($r['adres'] ?? null, $r['ADD_INFO'] ?? null, $r['ADMIN_NOTICES'] ?? null),
        ]);
        $copied++;
    }

    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');

    return [
        'source' => $src,
        'target' => $dst,
        'copied' => $copied,
        'named_from_fallback' => $namedFromFallback,
    ];
};
