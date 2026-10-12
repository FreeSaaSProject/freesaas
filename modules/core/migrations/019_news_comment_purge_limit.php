<?php
declare(strict_types=1);

/**
 * Per-news auto-purge: keep at most COMMENT_PURGE_LIMIT comments (0 = off).
 */
return static function (PDO $pdo): void {
    if (!Database::tableExists('base_news')) {
        return;
    }
    if (Database::columnExists('base_news', 'COMMENT_PURGE_LIMIT')) {
        return;
    }
    $pdo->exec(
        'ALTER TABLE `base_news`
         ADD COLUMN `COMMENT_PURGE_LIMIT` INT UNSIGNED NOT NULL DEFAULT 0'
    );
};
