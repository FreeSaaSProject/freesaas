<?php
declare(strict_types=1);

/**
 * Checklist items on news/ticket cards (progress = done/total).
 */
return static function (PDO $pdo): void {
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS base_news_checklist (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            ID_NEWS INT UNSIGNED NOT NULL DEFAULT 0,
            TITLE VARCHAR(500) NOT NULL DEFAULT \'\',
            DONE TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            SORT INT UNSIGNED NOT NULL DEFAULT 0,
            DT_CREATE INT UNSIGNED NOT NULL DEFAULT 0,
            ID_USER INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (ID),
            KEY idx_news_checklist_news (ID_NEWS, SORT, ID)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
};
