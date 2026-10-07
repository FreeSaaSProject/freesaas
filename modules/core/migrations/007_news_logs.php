<?php
declare(strict_types=1);

/**
 * Ensure base_news_logs (body version history) exists; index by news id.
 */
return static function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_news_logs (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            ID_OBJ INT UNSIGNED NOT NULL,
            ID_USER INT UNSIGNED NOT NULL,
            DT_CREATE INT UNSIGNED NOT NULL,
            DT_UPDATE INT UNSIGNED NOT NULL,
            CONTENT MEDIUMTEXT NOT NULL,
            PRIMARY KEY (ID),
            KEY idx_news_logs_obj (ID_OBJ, DT_UPDATE)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $exists = static function (string $table, string $name) use ($pdo): bool {
        $st = $pdo->prepare(
            'SELECT 1 FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1'
        );
        $st->execute([$table, $name]);
        return (bool)$st->fetchColumn();
    };

    if (!$exists('base_news_logs', 'idx_news_logs_obj')) {
        try {
            $pdo->exec(
                'ALTER TABLE base_news_logs ADD INDEX idx_news_logs_obj (ID_OBJ, DT_UPDATE)'
            );
        } catch (Throwable $e) {
            // may already exist under another name
        }
    }
};
