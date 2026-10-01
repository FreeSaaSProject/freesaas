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

    $src = 'phpshop_shopusers_status';
    $dst = 'base_client_statuses';
    if (!$tableExists($pdo, $src)) {
        throw new RuntimeException("Source table missing: $src");
    }
    if (!$tableExists($pdo, $dst)) {
        throw new RuntimeException("Target table missing: $dst (run schema migrate first)");
    }

    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    $pdo->exec('TRUNCATE TABLE `base_client_statuses`');

    $rows = $pdo->query(
        'SELECT id, name, discount, price, enabled, bonus, count_abs FROM `phpshop_shopusers_status`'
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $ins = $pdo->prepare(
        'INSERT INTO `base_client_statuses`
            (id, name, discount, price_tier, bonus, count_abs, enabled)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );

    $copied = 0;
    foreach ($rows as $r) {
        $price = (int)($r['price'] ?? 1);
        if ($price < 1 || $price > 5) {
            $price = 1;
        }
        $enabled = ((string)($r['enabled'] ?? '1') === '1' || (int)($r['enabled'] ?? 1) === 1) ? 1 : 0;
        $ins->execute([
            (int)$r['id'],
            (string)($r['name'] ?? ''),
            (float)($r['discount'] ?? 0),
            $price,
            isset($r['bonus']) && $r['bonus'] !== null && $r['bonus'] !== '' ? (float)$r['bonus'] : null,
            isset($r['count_abs']) && $r['count_abs'] !== null && $r['count_abs'] !== ''
                ? (int)$r['count_abs']
                : null,
            $enabled,
        ]);
        $copied++;
    }

    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');

    return ['source' => $src, 'target' => $dst, 'copied' => $copied];
};
