<?php
declare(strict_types=1);

/**
 * Slim client CRM: statuses + clients (no medical / 1C / beauty-card blobs).
 */
return static function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_client_statuses (
            id TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL DEFAULT '',
            discount FLOAT NOT NULL DEFAULT 0,
            price_tier TINYINT UNSIGNED NOT NULL DEFAULT 1,
            bonus FLOAT NULL,
            count_abs TINYINT UNSIGNED NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_clients (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL DEFAULT '',
            tel VARCHAR(64) NOT NULL DEFAULT '',
            mail VARCHAR(64) NOT NULL DEFAULT '',
            password VARCHAR(64) NOT NULL DEFAULT '',
            birthday DATE NULL,
            gender TINYINT UNSIGNED NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 0,
            registered_at INT UNSIGNED NULL,
            status_id TINYINT UNSIGNED NOT NULL DEFAULT 0,
            staff_user_id INT UNSIGNED NULL,
            surname VARCHAR(255) NULL,
            pname VARCHAR(255) NULL,
            patronymic VARCHAR(255) NULL,
            passport_serie VARCHAR(4) NULL,
            passport_number VARCHAR(6) NULL,
            passport_issued_by VARCHAR(255) NULL,
            passport_issued_at VARCHAR(255) NULL,
            passport_birthday VARCHAR(255) NULL,
            address_passport VARCHAR(255) NULL,
            address_fact VARCHAR(255) NULL,
            subscribe TINYINT(1) NOT NULL DEFAULT 0,
            notes MEDIUMTEXT NULL,
            PRIMARY KEY (id),
            KEY idx_tel (tel),
            KEY idx_mail (mail),
            KEY idx_status (status_id),
            KEY idx_staff (staff_user_id),
            KEY idx_enabled (enabled)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
};
