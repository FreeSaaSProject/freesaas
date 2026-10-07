<?php
declare(strict_types=1);

/**
 * Ensure base_likes exists (news / comment likes via LikesRepository).
 */
return static function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_likes (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            DT INT NOT NULL,
            ID_USER INT NOT NULL,
            ID_OBJ INT NOT NULL,
            TYPE_OBJ VARCHAR(255) NOT NULL,
            ID_USER_AUTHOR INT UNSIGNED NOT NULL,
            LIKE_VIEWED TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (ID),
            KEY idx_likes_lookup (TYPE_OBJ(191), ID_OBJ, ID_USER)
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

    if (!$exists('base_likes', 'idx_likes_lookup')) {
        try {
            $pdo->exec(
                'ALTER TABLE base_likes ADD INDEX idx_likes_lookup (TYPE_OBJ(191), ID_OBJ, ID_USER)'
            );
        } catch (Throwable $e) {
            // similar key may already exist
        }
    }
};
