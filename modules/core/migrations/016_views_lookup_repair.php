<?php
declare(strict_types=1);

/**
 * Repair base_views indexes.
 *
 * Legacy MyISAM dumps often ship keys named ID_USER / ID_OBJ that only cover
 * TYPE_OBJ(191). unread notices then scan ~full table per news row.
 * Ensure composite idx_views_lookup (+ obj lookup) and drop the useless keys.
 */
return static function (PDO $pdo): void {
    if (!Database::tableExists('base_views')) {
        return;
    }

    $indexExists = static function (string $name) use ($pdo): bool {
        $st = $pdo->prepare(
            'SELECT 1 FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = \'base_views\' AND INDEX_NAME = ? LIMIT 1'
        );
        $st->execute([$name]);
        return (bool)$st->fetchColumn();
    };

    /** @return list<string> */
    $indexColumns = static function (string $name) use ($pdo): array {
        $st = $pdo->prepare(
            'SELECT COLUMN_NAME FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = \'base_views\' AND INDEX_NAME = ?
             ORDER BY SEQ_IN_INDEX'
        );
        $st->execute([$name]);
        $cols = [];
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $c) {
            $cols[] = (string)$c;
        }
        return $cols;
    };

    // Drop legacy single-column TYPE_OBJ keys with misleading names.
    foreach (['ID_USER', 'ID_OBJ', 'ID_OBJ_2'] as $legacy) {
        if (!$indexExists($legacy)) {
            continue;
        }
        $cols = $indexColumns($legacy);
        if ($cols === ['TYPE_OBJ']) {
            try {
                $pdo->exec('ALTER TABLE base_views DROP INDEX `' . str_replace('`', '``', $legacy) . '`');
            } catch (Throwable $e) {
                // ignore — may be in use / already dropped
            }
        }
    }

    if (!$indexExists('idx_views_lookup')) {
        try {
            $pdo->exec(
                'ALTER TABLE base_views
                 ADD INDEX idx_views_lookup (TYPE_OBJ(191), ID_USER, ID_OBJ)'
            );
        } catch (Throwable $e) {
            // ignore conflict with an equivalent key
        }
    }

    if (!$indexExists('idx_views_obj')) {
        try {
            $pdo->exec(
                'ALTER TABLE base_views
                 ADD INDEX idx_views_obj (ID_OBJ, TYPE_OBJ(191), ID_USER)'
            );
        } catch (Throwable $e) {
            // ignore
        }
    }
};
