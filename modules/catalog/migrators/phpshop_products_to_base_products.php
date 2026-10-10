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

    $src = 'phpshop_products';
    $dst = 'base_products';
    if (!$tableExists($pdo, $src)) {
        throw new RuntimeException("Source table missing: $src");
    }
    if (!$tableExists($pdo, $dst)) {
        throw new RuntimeException("Target table missing: $dst (run schema migrate first)");
    }

    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    $pdo->exec("TRUNCATE TABLE `$dst`");

    $rows = $pdo->query(
        'SELECT id, category, name, name_price, description, enabled, num,
                price, price2, price3, price4, price5,
                IS_MATERIAL, TYPE, TIME, TIME_NET, DESCR_PROC,
                ID_UNIT, ID_UNITU, ID_SUPPLIER, AMOUNT_IN_STOCK_REQ, AMOUNT_IN_STOCK_MAX,
                IS_STATUS_CARD, HIDE_IN_HISTORY,
                `1C_ID`, dop_cat, AMOUNT_DONE, ID_ADMIN_LAST_UPDATE, DT_ADMIN_LAST_UPDATE
         FROM phpshop_products'
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $ins = $pdo->prepare(
        'INSERT INTO base_products (
            id, category, name, name_price, description, enabled, num,
            price, price2, price3, price4, price5,
            is_material, type_id, time_sec, time_net, descr_proc,
            unit_id, unit_use_id, supplier_id, stock_min, stock_max,
            hide_on_site, hide_in_history,
            onec_id, dop_cat, amount_done, last_admin_id, last_admin_at
         ) VALUES (
            ?, ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?,
            ?, ?,
            ?, ?, ?, ?, ?
         )'
    );

    $enum01 = static function ($v): int {
        return ((string)$v === '1' || (int)$v === 1) ? 1 : 0;
    };

    $nullableText = static function ($v): ?string {
        if ($v === null) {
            return null;
        }
        $s = trim((string)$v);
        return $s === '' ? null : $s;
    };

    $copied = 0;
    foreach ($rows as $r) {
        $ins->execute([
            (int)$r['id'],
            (int)($r['category'] ?? 0),
            (string)($r['name'] ?? ''),
            (string)($r['name_price'] ?? ''),
            $nullableText($r['description'] ?? null),
            $enum01($r['enabled'] ?? 1),
            (int)($r['num'] ?? 0),
            (float)($r['price'] ?? 0),
            (float)($r['price2'] ?? 0),
            (float)($r['price3'] ?? 0),
            (float)($r['price4'] ?? 0),
            (float)($r['price5'] ?? 0),
            (int)($r['IS_MATERIAL'] ?? 0) ? 1 : 0,
            (int)($r['TYPE'] ?? 0),
            (int)($r['TIME'] ?? 0),
            (int)($r['TIME_NET'] ?? 0),
            $nullableText($r['DESCR_PROC'] ?? null),
            (int)($r['ID_UNIT'] ?? 0),
            (int)($r['ID_UNITU'] ?? 0),
            (int)($r['ID_SUPPLIER'] ?? 0),
            (int)($r['AMOUNT_IN_STOCK_REQ'] ?? 0),
            (int)($r['AMOUNT_IN_STOCK_MAX'] ?? 0),
            (int)($r['IS_STATUS_CARD'] ?? 0) ? 1 : 0,
            (int)($r['HIDE_IN_HISTORY'] ?? 0) ? 1 : 0,
            (string)($r['1C_ID'] ?? ''),
            (string)($r['dop_cat'] ?? ''),
            (int)($r['AMOUNT_DONE'] ?? 0),
            (int)($r['ID_ADMIN_LAST_UPDATE'] ?? 0),
            (int)($r['DT_ADMIN_LAST_UPDATE'] ?? 0),
        ]);
        $copied++;
    }

    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');

    return [
        'copied' => $copied,
        'source' => $src,
        'target' => $dst,
    ];
};
