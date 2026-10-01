<?php
declare(strict_types=1);

/**
 * Parse legacy phpshop_shopusers.TAGS (,#1#,#5#) into base_client_tag_links.
 * Run after phpshop_shopusers_to_base_clients (same ids).
 */
return static function (PDO $pdo, array $spec): array {
    $tableExists = static function (PDO $pdo, string $table): bool {
        $st = $pdo->prepare(
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
        );
        $st->execute([$table]);
        return (bool)$st->fetchColumn();
    };

    $src = 'phpshop_shopusers';
    $dst = 'base_client_tag_links';
    if (!$tableExists($pdo, $src)) {
        throw new RuntimeException("Source table missing: $src");
    }
    if (!$tableExists($pdo, $dst)) {
        throw new RuntimeException("Target table missing: $dst (run schema migrate first)");
    }
    if (!$tableExists($pdo, 'base_clients')) {
        throw new RuntimeException('base_clients missing — run clients migrator first');
    }

    $colExists = static function (PDO $pdo, string $table, string $col): bool {
        $st = $pdo->prepare(
            'SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
        );
        $st->execute([$table, $col]);
        return (bool)$st->fetchColumn();
    };
    if (!$colExists($pdo, $src, 'TAGS')) {
        return ['source' => $src, 'target' => $dst, 'copied' => 0, 'note' => 'TAGS column missing'];
    }

    $validTagIds = [];
    if ($tableExists($pdo, 'base_shopusers_tags')) {
        foreach ($pdo->query('SELECT ID FROM base_shopusers_tags')->fetchAll(PDO::FETCH_COLUMN) ?: [] as $tid) {
            $validTagIds[(int)$tid] = true;
        }
    }

    $clientIds = [];
    foreach ($pdo->query('SELECT id FROM base_clients')->fetchAll(PDO::FETCH_COLUMN) ?: [] as $cid) {
        $clientIds[(int)$cid] = true;
    }

    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    $pdo->exec('TRUNCATE TABLE `base_client_tag_links`');

    $rows = $pdo->query(
        'SELECT id, TAGS FROM `phpshop_shopusers` WHERE TAGS IS NOT NULL AND TAGS <> \'\''
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $ins = $pdo->prepare(
        'INSERT IGNORE INTO `base_client_tag_links` (client_id, tag_id) VALUES (?, ?)'
    );

    $copied = 0;
    $skipped = 0;
    foreach ($rows as $r) {
        $clientId = (int)($r['id'] ?? 0);
        if ($clientId < 1 || !isset($clientIds[$clientId])) {
            $skipped++;
            continue;
        }
        $raw = (string)($r['TAGS'] ?? '');
        if ($raw === '') {
            continue;
        }
        if (!preg_match_all('/#(\d+)#/', $raw, $m)) {
            continue;
        }
        $seen = [];
        foreach ($m[1] as $tidStr) {
            $tagId = (int)$tidStr;
            if ($tagId < 1 || isset($seen[$tagId])) {
                continue;
            }
            $seen[$tagId] = true;
            if ($validTagIds !== [] && !isset($validTagIds[$tagId])) {
                continue;
            }
            $ins->execute([$clientId, $tagId]);
            $copied += $ins->rowCount();
        }
    }

    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');

    return [
        'source' => $src . '.TAGS',
        'target' => $dst,
        'copied' => $copied,
        'skipped_clients' => $skipped,
    ];
};
