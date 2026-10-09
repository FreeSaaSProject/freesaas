<?php
declare(strict_types=1);

/**
 * Legacy role flags on base_admin_statuses (additive, idempotent).
 *
 * Migration 005 creates base_admin_statuses with only the platform columns, but
 * module-registered field groups (workweeks / calendar / stocks / audio / clients)
 * and Auth::capability() / Auth::statusFlags() expect the legacy oper flags.
 *
 * Without EVT_ASSIGN / EVT_ASSIGN_MY, shift assignment UI hides fields and non-superadmin
 * roles cannot assign. Superadmin bypasses capability(), but role edit forms still need
 * the columns, and greenfield Superadmin should own EVT_ASSIGN + CAN_BE_ASSIGNED.
 *
 * Columns are added only when missing — legacy oper DBs are untouched.
 */
return static function (PDO $pdo): void {
    if (!Database::tableExists('base_admin_statuses')) {
        return;
    }

    $flags = [
        // workweeks (shift assignment)
        'SHOW_LOAD_LEVEL' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'SHOW_IN_LOADS' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'CAN_BE_ASSIGNED' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'CAN_BE_ASSIGNED_ASANY' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'EVT_ASSIGN' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'EVT_ASSIGN_MY' => 'TINYINT(1) NOT NULL DEFAULT 0',
        // calendar (events)
        'EVT_CONTACT_DATA' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'EVT_DOC_LINKS' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'EVT_DELETE' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'EVT_PHOTOS' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'EVT_MY_ONLY' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'EVT_OLD' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'EVT_BONUS' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'EVT_MONEY_DATA' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'EVT_CREATE_NEW' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'EVT_MODIFY' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'DISABLE_NON_PROCEDURE_TIME' => 'TINYINT(1) NOT NULL DEFAULT 0',
        // stocks / warehouse
        'STOCKS_DATA' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'STICKER_ADV_ACC' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'GOOD_USE' => 'TINYINT(1) NOT NULL DEFAULT 0',
        // clients
        'CLIENT_BASE' => 'TINYINT(1) NOT NULL DEFAULT 0',
        // audio analytics position
        'ID_AUDIO_POSITION' => 'INT NOT NULL DEFAULT 0',
        // legacy misc flags kept for compatibility with older module screens
        'SHOW_OS' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'IS_DOCTOR' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'SHOW_MEDINFO' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'MARKET_ACCESS' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'STA_ACCESS' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'ZGV_ACCESS' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'CAN_SEE_KPI_OTHERS' => 'TINYINT(1) NOT NULL DEFAULT 0',
    ];

    foreach ($flags as $col => $ddl) {
        if (!Database::columnExists('base_admin_statuses', $col)) {
            $pdo->exec('ALTER TABLE `base_admin_statuses` ADD COLUMN `' . $col . '` ' . $ddl);
            Database::clearSchemaCache();
        }
    }

    // Ensure Superadmin can assign shifts after columns appear (greenfield defaults are 0).
    if (Database::columnExists('base_admin_statuses', 'SUPERADMIN_ACCESS')
        && Database::columnExists('base_admin_statuses', 'EVT_ASSIGN')
        && Database::columnExists('base_admin_statuses', 'CAN_BE_ASSIGNED')
    ) {
        $pdo->exec(
            'UPDATE base_admin_statuses
             SET EVT_ASSIGN = 1, CAN_BE_ASSIGNED = 1
             WHERE SUPERADMIN_ACCESS > 0
               AND (EVT_ASSIGN < 1 OR CAN_BE_ASSIGNED < 1)'
        );
    }
};
