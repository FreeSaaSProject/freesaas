<?php
declare(strict_types=1);

/**
 * Shared assistant conversation per staff user (web + messenger bots).
 */
return static function (PDO $pdo): void {
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS base_assistant_dialog (
            ID_USER INT UNSIGNED NOT NULL,
            HISTORY_JSON MEDIUMTEXT NULL,
            DTUPDATE INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (ID_USER),
            KEY idx_assistant_dialog_upd (DTUPDATE)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
};
