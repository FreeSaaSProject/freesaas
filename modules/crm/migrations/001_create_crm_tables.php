<?php
declare(strict_types=1);

/** Sales CRM core: companies, pipelines/stages, deals, links, custom fields, notes, stage history. */
return static function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_crm_companies (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL DEFAULT '',
            inn VARCHAR(32) NOT NULL DEFAULT '',
            kpp VARCHAR(32) NOT NULL DEFAULT '',
            phone VARCHAR(64) NOT NULL DEFAULT '',
            email VARCHAR(128) NOT NULL DEFAULT '',
            website VARCHAR(255) NOT NULL DEFAULT '',
            address VARCHAR(512) NOT NULL DEFAULT '',
            notes MEDIUMTEXT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            responsible_user_id INT UNSIGNED NOT NULL DEFAULT 0,
            created_at INT UNSIGNED NOT NULL DEFAULT 0,
            updated_at INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY idx_crm_co_name (name),
            KEY idx_crm_co_inn (inn),
            KEY idx_crm_co_enabled (enabled)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_crm_company_contacts (
            company_id INT UNSIGNED NOT NULL,
            client_id INT UNSIGNED NOT NULL,
            is_primary TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (company_id, client_id),
            KEY idx_crm_co_ct_client (client_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_crm_pipelines (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(128) NOT NULL DEFAULT '',
            kind VARCHAR(16) NOT NULL DEFAULT 'sale',
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0,
            created_at INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY idx_crm_pipe_kind (kind, enabled)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_crm_stages (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            pipeline_id INT UNSIGNED NOT NULL DEFAULT 0,
            name VARCHAR(128) NOT NULL DEFAULT '',
            code VARCHAR(64) NOT NULL DEFAULT '',
            semantic CHAR(1) NOT NULL DEFAULT 'P',
            sort_order INT NOT NULL DEFAULT 0,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (id),
            KEY idx_crm_stage_pipe (pipeline_id, sort_order),
            UNIQUE KEY uq_crm_stage_code (pipeline_id, code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_crm_deals (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            title VARCHAR(255) NOT NULL DEFAULT '',
            pipeline_id INT UNSIGNED NOT NULL DEFAULT 0,
            stage_id INT UNSIGNED NOT NULL DEFAULT 0,
            company_id INT UNSIGNED NOT NULL DEFAULT 0,
            amount DECIMAL(18,2) NOT NULL DEFAULT 0,
            currency VARCHAR(8) NOT NULL DEFAULT 'RUB',
            responsible_user_id INT UNSIGNED NOT NULL DEFAULT 0,
            source VARCHAR(64) NOT NULL DEFAULT '',
            notes MEDIUMTEXT NULL,
            closed_at INT UNSIGNED NOT NULL DEFAULT 0,
            created_at INT UNSIGNED NOT NULL DEFAULT 0,
            updated_at INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY idx_crm_deal_pipe_stage (pipeline_id, stage_id),
            KEY idx_crm_deal_company (company_id),
            KEY idx_crm_deal_updated (updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_crm_deal_contacts (
            deal_id INT UNSIGNED NOT NULL,
            client_id INT UNSIGNED NOT NULL,
            is_primary TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (deal_id, client_id),
            KEY idx_crm_deal_ct_client (client_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_crm_custom_fields (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            entity VARCHAR(16) NOT NULL DEFAULT '',
            code VARCHAR(64) NOT NULL DEFAULT '',
            name VARCHAR(128) NOT NULL DEFAULT '',
            field_type VARCHAR(32) NOT NULL DEFAULT 'string',
            enums_json MEDIUMTEXT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (id),
            UNIQUE KEY uq_crm_cf (entity, code),
            KEY idx_crm_cf_entity (entity, enabled)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_crm_custom_values (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            field_id INT UNSIGNED NOT NULL DEFAULT 0,
            entity VARCHAR(16) NOT NULL DEFAULT '',
            entity_id INT UNSIGNED NOT NULL DEFAULT 0,
            value_text MEDIUMTEXT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_crm_cv (field_id, entity, entity_id),
            KEY idx_crm_cv_entity (entity, entity_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_crm_notes (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            entity VARCHAR(16) NOT NULL DEFAULT '',
            entity_id INT UNSIGNED NOT NULL DEFAULT 0,
            body MEDIUMTEXT NOT NULL,
            author_user_id INT UNSIGNED NOT NULL DEFAULT 0,
            created_at INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY idx_crm_note_entity (entity, entity_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_crm_stage_history (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            deal_id INT UNSIGNED NOT NULL DEFAULT 0,
            from_stage_id INT UNSIGNED NOT NULL DEFAULT 0,
            to_stage_id INT UNSIGNED NOT NULL DEFAULT 0,
            changed_at INT UNSIGNED NOT NULL DEFAULT 0,
            changed_by INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY idx_crm_sh_deal (deal_id, changed_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
};
