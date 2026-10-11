<?php
declare(strict_types=1);

/**
 * /t client history + client procedure lookups: ID_USER without index → full scan (~0.4s on 270k rows).
 */
return static function (PDO $pdo): void {
    $st = $pdo->query("SHOW TABLES LIKE 'base_procedures'");
    if ($st === false || $st->fetchColumn() === false) {
        return;
    }
    $check = $pdo->prepare(
        'SELECT 1 FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1'
    );
    $check->execute(['base_procedures', 'idx_id_user_dtstart']);
    if ($check->fetchColumn()) {
        return;
    }
    $pdo->exec(
        'ALTER TABLE base_procedures ADD INDEX idx_id_user_dtstart (ID_USER, DTSTART)'
    );
};
