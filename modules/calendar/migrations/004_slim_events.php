<?php
declare(strict_types=1);

/**
 * Phase 1 slim events: basket table, drop legacy columns / change log.
 * No CONTENT/CONTENT_PRICE backfill — prod uses events for blocks only.
 */
return static function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_event_items (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            ID_EVENT INT UNSIGNED NOT NULL DEFAULT 0,
            ID_GOOD INT UNSIGNED NOT NULL DEFAULT 0,
            PRICE DECIMAL(12,2) NOT NULL DEFAULT 0,
            QTY INT UNSIGNED NOT NULL DEFAULT 1,
            POS SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (ID),
            UNIQUE KEY uq_event_good (ID_EVENT, ID_GOOD),
            KEY idx_event (ID_EVENT),
            KEY idx_good (ID_GOOD)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    if (Database::tableExists('base_events_changes')) {
        $pdo->exec('DROP TABLE IF EXISTS base_events_changes');
    }

    if (!Database::tableExists('base_events')) {
        return;
    }

    $dropCols = [
        'SMS',
        'NODEFTIMEXPL',
        'PPP_LOG',
        'UPSALE_DATA',
        'CONTENT_DATA',
        'NONEWEXPL',
        'DTENABLE',
        'OPERATOR_ENABLED',
        'EVT_TIME_RECOM',
        'EVT_TIME_REAL',
        'EVT_COSM_ACCEPT',
        'EVT_DECLINE_REASON',
        'EVT_DT_COSM_MOD',
        'ID_PROMO_ACT',
        'PAY',
        'FIX_MOVE',
        'CONTENT',
        'CONTENT_PRICE',
        'BONUS_DISCOUNT',
        'BONUS_USE',
        'REAL_SUMM',
    ];
    foreach ($dropCols as $col) {
        if (Database::columnExists('base_events', $col)) {
            $pdo->exec('ALTER TABLE `base_events` DROP COLUMN `' . $col . '`');
        }
    }
};
