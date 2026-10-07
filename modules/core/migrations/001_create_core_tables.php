<?php
declare(strict_types=1);

/**
 * Core schema: base_users, base_system, module registry, schema ledger.
 * When source oper tables exist, clone structure into base_*; otherwise create minimal DDL.
 */
return static function (PDO $pdo): void {
    // base_users — structural copy from oper when present (local/transition installs)
    $src = $pdo->query("SHOW TABLES LIKE 'phpshop_users'")->fetchColumn();
    if ($src) {
        $pdo->exec('CREATE TABLE IF NOT EXISTS base_users LIKE phpshop_users');
    } else {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS base_users (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                status BLOB NOT NULL,
                login VARCHAR(64) NOT NULL DEFAULT '',
                password VARCHAR(64) NOT NULL DEFAULT '',
                mail VARCHAR(64) NOT NULL DEFAULT '',
                enabled ENUM('0','1') NOT NULL DEFAULT '0',
                content MEDIUMTEXT NOT NULL,
                skin VARCHAR(255) NOT NULL DEFAULT '',
                skin_enabled ENUM('0','1') NOT NULL DEFAULT '0',
                name VARCHAR(255) NOT NULL DEFAULT '',
                ID_STATUS INT NOT NULL DEFAULT 0,
                ID_STATUS2 INT NOT NULL DEFAULT 0,
                color VARCHAR(255) NOT NULL DEFAULT '',
                color_text VARCHAR(255) NOT NULL DEFAULT '',
                url VARCHAR(256) NOT NULL DEFAULT '',
                retries TINYINT UNSIGNED NOT NULL DEFAULT 0,
                expire INT NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                KEY idx_login (login)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    $srcSys = $pdo->query("SHOW TABLES LIKE 'phpshop_system'")->fetchColumn();
    if ($srcSys) {
        $pdo->exec('CREATE TABLE IF NOT EXISTS base_system LIKE phpshop_system');
    } else {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS base_system (
                id INT NOT NULL AUTO_INCREMENT,
                name MEDIUMTEXT NULL,
                company MEDIUMTEXT NULL,
                title VARCHAR(255) NOT NULL DEFAULT '',
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_modules (
            id VARCHAR(64) NOT NULL,
            name VARCHAR(255) NOT NULL DEFAULT '',
            version VARCHAR(32) NOT NULL DEFAULT '0.0.0',
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            installed_at INT UNSIGNED NOT NULL DEFAULT 0,
            updated_at INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    // base_schema_migrations created by SchemaMigrator::ensureLedger as well
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_schema_migrations (
            module_id VARCHAR(64) NOT NULL,
            migration_id VARCHAR(128) NOT NULL,
            applied_at INT UNSIGNED NOT NULL,
            PRIMARY KEY (module_id, migration_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
};
