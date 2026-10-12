<?php
declare(strict_types=1);

/**
 * Atomic one-shot QR operation tokens (claim under UNIQUE; rolls back with the writeoff txn).
 *
 * @return callable(PDO):void
 */
return static function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_qr_token_claims (
            TOKEN CHAR(32) NOT NULL,
            DT_CREATE INT UNSIGNED NOT NULL DEFAULT 0,
            ID_STICKER INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (TOKEN),
            KEY idx_sticker (ID_STICKER)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
};
