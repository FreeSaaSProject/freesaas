<?php
declare(strict_types=1);

/**
 * Indexes for unread notices (base_news / base_views).
 */
return static function (PDO $pdo): void {
    $exists = static function (string $table, string $name) use ($pdo): bool {
        $st = $pdo->prepare(
            'SELECT 1 FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1'
        );
        $st->execute([$table, $name]);
        return (bool)$st->fetchColumn();
    };

    if (Database::tableExists('base_news') && !$exists('base_news', 'idx_news_feed')) {
        $pdo->exec(
            'ALTER TABLE base_news
             ADD INDEX idx_news_feed (DISABLED, HAS_CHILDREN, TYPE, STATUS, DT_DEADLINE)'
        );
    }
    if (Database::tableExists('base_views') && !$exists('base_views', 'idx_views_lookup')) {
        // Cover NOT EXISTS unread check
        try {
            $pdo->exec(
                'ALTER TABLE base_views
                 ADD INDEX idx_views_lookup (TYPE_OBJ(191), ID_USER, ID_OBJ)'
            );
        } catch (Throwable $e) {
            // index may conflict with existing similar key — ignore
        }
    }
};
