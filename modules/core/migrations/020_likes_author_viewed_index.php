<?php
declare(strict_types=1);

/**
 * Index for home “unviewed likes for author” query.
 */
return static function (PDO $pdo): void {
    if (!Database::tableExists('base_likes')) {
        return;
    }

    $st = $pdo->prepare(
        'SELECT 1 FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1'
    );
    $st->execute(['base_likes', 'idx_likes_author_viewed']);
    if ($st->fetchColumn()) {
        return;
    }

    try {
        $pdo->exec(
            'ALTER TABLE base_likes
             ADD INDEX idx_likes_author_viewed (ID_USER_AUTHOR, LIKE_VIEWED, DT)'
        );
    } catch (Throwable $e) {
        // similar key may already exist
    }
};
