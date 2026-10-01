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

    $src = 'phpshop_categories';
    $dst = 'base_categories';
    if (!$tableExists($pdo, $src)) {
        throw new RuntimeException("Source table missing: $src");
    }
    if (!$tableExists($pdo, $dst)) {
        throw new RuntimeException("Target table missing: $dst (run schema migrate first)");
    }

    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    $pdo->exec("TRUNCATE TABLE `$dst`");

    $rows = $pdo->query(
        'SELECT id, name, name_short, parent_to, num, secure_groups, NO_CART, EXECUTORS, review
         FROM phpshop_categories'
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $ins = $pdo->prepare(
        'INSERT INTO base_categories
         (id, name, name_short, parent_id, num, hidden, no_cart, executors, review)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );

    $copied = 0;
    foreach ($rows as $r) {
        $executors = $r['EXECUTORS'] ?? null;
        if ($executors !== null && trim((string)$executors) === '') {
            $executors = null;
        }
        $review = $r['review'] ?? null;
        if ($review !== null && trim((string)$review) === '') {
            $review = null;
        }
        // Legacy checkbox «Скрыть каталог» stored in secure_groups ('1' = hidden).
        $hidden = ((string)($r['secure_groups'] ?? '0') === '1' || (int)($r['secure_groups'] ?? 0) === 1) ? 1 : 0;
        $ins->execute([
            (int)$r['id'],
            (string)($r['name'] ?? ''),
            (string)($r['name_short'] ?? ''),
            (int)($r['parent_to'] ?? 0),
            (int)($r['num'] ?? 0),
            $hidden,
            (int)($r['NO_CART'] ?? 0) ? 1 : 0,
            $executors,
            $review,
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
