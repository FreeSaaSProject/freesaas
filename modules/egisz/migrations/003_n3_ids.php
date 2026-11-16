<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

return static function (PDO $pdo): void {
    if (Database::tableExists('base_egisz_client_ids') && !Database::columnExists('base_egisz_client_ids', 'N3_ID')) {
        $pdo->exec(
            'ALTER TABLE `base_egisz_client_ids`
             ADD COLUMN `N3_ID` VARCHAR(64) NULL DEFAULT NULL AFTER `OMS_INSURER`,
             ADD COLUMN `ID_PATIENT_MIS` VARCHAR(64) NULL DEFAULT NULL AFTER `N3_ID`,
             ADD KEY `idx_egisz_client_n3` (`N3_ID`)'
        );
    }

    if (Database::tableExists('base_egisz_outbox')) {
        if (!Database::columnExists('base_egisz_outbox', 'ID_CASE_MIS')) {
            $pdo->exec(
                'ALTER TABLE `base_egisz_outbox`
                 ADD COLUMN `ID_CASE_MIS` CHAR(36) NULL DEFAULT NULL AFTER `LOCAL_UID`,
                 ADD COLUMN `ID_PATIENT_MIS` VARCHAR(64) NULL DEFAULT NULL AFTER `ID_CASE_MIS`,
                 ADD KEY `idx_egisz_outbox_case` (`ID_CASE_MIS`)'
            );
        }
        if (!Database::columnExists('base_egisz_outbox', 'RAW_RESPONSE')) {
            $pdo->exec(
                'ALTER TABLE `base_egisz_outbox`
                 ADD COLUMN `RAW_RESPONSE` MEDIUMTEXT NULL AFTER `ERROR_TEXT`'
            );
        }
    }
};
