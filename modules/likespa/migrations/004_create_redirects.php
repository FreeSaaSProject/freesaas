<?php
declare(strict_types=1);

/**
 * Site URL redirects (oper show_redirects → base_redirects).
 */
return static function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_redirects (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            DT_CREATE INT UNSIGNED NOT NULL DEFAULT 0,
            URL_OLD VARCHAR(255) NOT NULL DEFAULT '',
            URL_NEW VARCHAR(255) NOT NULL DEFAULT '',
            ENABLED TINYINT UNSIGNED NOT NULL DEFAULT 1,
            PRIMARY KEY (ID),
            KEY idx_enabled (ENABLED),
            KEY idx_url_old (URL_OLD)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
};
