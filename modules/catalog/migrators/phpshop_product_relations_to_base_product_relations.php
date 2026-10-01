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

    $src = 'phpshop_product_relations';
    $dst = 'base_product_relations';
    if (!$tableExists($pdo, $src)) {
        throw new RuntimeException("Source table missing: $src");
    }
    if (!$tableExists($pdo, $dst)) {
        throw new RuntimeException("Target table missing: $dst (run schema migrate first)");
    }

    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    $pdo->exec("TRUNCATE TABLE `$dst`");

    $rows = $pdo->query(
        'SELECT ID, ID_PRODUCT_FROM, ID_PRODUCT_TO, TYPE, BENEFIT, DT_CREATE, ENABLED
         FROM phpshop_product_relations'
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $ins = $pdo->prepare(
        'INSERT INTO base_product_relations
         (id, product_from_id, product_to_id, type, benefit, created_at, enabled)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );

    $allowedTypes = ['addon' => true, 'upgrade' => true, 'component' => true];
    $copied = 0;
    foreach ($rows as $r) {
        $type = (string)($r['TYPE'] ?? 'addon');
        if (!isset($allowedTypes[$type])) {
            $type = 'addon';
        }
        $benefit = $r['BENEFIT'] ?? null;
        if ($benefit !== null && trim((string)$benefit) === '') {
            $benefit = null;
        }
        $ins->execute([
            (int)$r['ID'],
            (int)($r['ID_PRODUCT_FROM'] ?? 0),
            (int)($r['ID_PRODUCT_TO'] ?? 0),
            $type,
            $benefit,
            (int)($r['DT_CREATE'] ?? 0),
            (int)($r['ENABLED'] ?? 0) ? 1 : 0,
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
