<?php
declare(strict_types=1);

/**
 * FreeSaaS-native client bonus ledger. Drops legacy base_bonuspay if present.
 */
return static function (PDO $pdo): void {
    $pdo->exec('DROP TABLE IF EXISTS base_bonuspay');
    $pdo->exec('DROP TABLE IF EXISTS base_bonus_moves');
    $pdo->exec(
        'CREATE TABLE base_bonus_moves (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            client_id INT UNSIGNED NOT NULL,
            direction SMALLINT NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            kind VARCHAR(32) NOT NULL,
            ref_type VARCHAR(16) NULL,
            ref_id INT UNSIGNED NULL,
            created_at INT UNSIGNED NOT NULL,
            expires_at INT UNSIGNED NULL,
            note VARCHAR(500) NOT NULL DEFAULT \'\',
            dismissed TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY idx_bonus_moves_client (client_id, created_at),
            KEY idx_bonus_moves_ref (ref_type, ref_id),
            KEY idx_bonus_moves_kind (kind)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
};
