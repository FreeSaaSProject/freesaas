<?php
declare(strict_types=1);

/**
 * Explicit audio analytics rates (rub/hour, rub/script-score point).
 * Backfill AUDIO_HOUR_RATE from LOSS where oper stored rub/hour in that field.
 */
return static function (PDO $pdo): void {
    $ensure = static function (PDO $pdo, string $col, string $ddl): void {
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $st->execute(['base_procent_history', $col]);
        if ((int)$st->fetchColumn() < 1) {
            $pdo->exec('ALTER TABLE base_procent_history ADD COLUMN ' . $ddl);
        }
    };

    $ensure($pdo, 'AUDIO_HOUR_RATE', 'AUDIO_HOUR_RATE DOUBLE NOT NULL DEFAULT 0');
    $ensure($pdo, 'SCRIPT_SCORE_RATE', 'SCRIPT_SCORE_RATE DOUBLE NOT NULL DEFAULT 0');

    $pdo->exec(
        'UPDATE base_procent_history
         SET AUDIO_HOUR_RATE = LOSS
         WHERE AUDIO_HOUR_RATE = 0 AND LOSS > 0'
    );
};
