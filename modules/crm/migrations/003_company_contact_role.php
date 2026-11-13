<?php
declare(strict_types=1);

/** Contact role inside a company (e.g. decision maker, contact person). */
return static function (PDO $pdo): void {
    $st = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'base_crm_company_contacts' AND COLUMN_NAME = 'role'"
    );
    if ((int)($st ? $st->fetchColumn() : 0) === 0) {
        $pdo->exec(
            "ALTER TABLE base_crm_company_contacts
             ADD COLUMN role VARCHAR(64) NOT NULL DEFAULT '' AFTER is_primary"
        );
    }
};
