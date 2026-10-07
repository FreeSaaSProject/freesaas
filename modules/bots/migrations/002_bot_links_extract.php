<?php
declare(strict_types=1);

/**
 * Move messenger ids off base_users / base_clients into base_bot_links (bots-owned).
 */
return static function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `base_bot_links` (
            `ID` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `BOT_SOURCE` TINYINT UNSIGNED NOT NULL DEFAULT 2,
            `ID_ENTITY` INT UNSIGNED NOT NULL DEFAULT 0,
            `telegram_chat_id` BIGINT NULL DEFAULT NULL,
            `max_user_id` BIGINT NULL DEFAULT NULL,
            `BOT_NO_SEND` TINYINT(1) NOT NULL DEFAULT 0,
            `DTCREATE` INT UNSIGNED NOT NULL DEFAULT 0,
            `DTUPDATE` INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`ID`),
            UNIQUE KEY `uq_bot_links_source_entity` (`BOT_SOURCE`, `ID_ENTITY`),
            KEY `idx_bot_links_tg` (`telegram_chat_id`),
            KEY `idx_bot_links_max` (`max_user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    Database::clearSchemaCache();

    $now = time();

    // Staff: base_users → BOT_SOURCE=2
    if (Database::tableExists('base_users')
        && (Database::columnExists('base_users', 'telegram_chat_id')
            || Database::columnExists('base_users', 'max_user_id')
            || Database::columnExists('base_users', 'BOT_NO_SEND'))
    ) {
        $hasTg = Database::columnExists('base_users', 'telegram_chat_id');
        $hasMax = Database::columnExists('base_users', 'max_user_id');
        $hasNo = Database::columnExists('base_users', 'BOT_NO_SEND');
        $tgExpr = $hasTg ? 'telegram_chat_id' : 'NULL';
        $maxExpr = $hasMax ? 'max_user_id' : 'NULL';
        $noExpr = $hasNo ? 'BOT_NO_SEND' : '0';
        $where = [];
        if ($hasTg) {
            $where[] = '(telegram_chat_id IS NOT NULL AND telegram_chat_id <> 0)';
        }
        if ($hasMax) {
            $where[] = '(max_user_id IS NOT NULL AND max_user_id <> 0)';
        }
        if ($hasNo) {
            $where[] = 'BOT_NO_SEND <> 0';
        }
        if ($where !== []) {
            $pdo->exec(
                'INSERT INTO base_bot_links
                 (BOT_SOURCE, ID_ENTITY, telegram_chat_id, max_user_id, BOT_NO_SEND, DTCREATE, DTUPDATE)
                 SELECT 2, id, ' . $tgExpr . ', ' . $maxExpr . ', ' . $noExpr . ', ' . $now . ', ' . $now . '
                 FROM base_users
                 WHERE ' . implode(' OR ', $where) . '
                 ON DUPLICATE KEY UPDATE
                   telegram_chat_id = COALESCE(VALUES(telegram_chat_id), base_bot_links.telegram_chat_id),
                   max_user_id = COALESCE(VALUES(max_user_id), base_bot_links.max_user_id),
                   BOT_NO_SEND = GREATEST(VALUES(BOT_NO_SEND), base_bot_links.BOT_NO_SEND),
                   DTUPDATE = VALUES(DTUPDATE)'
            );
        }
        foreach (['telegram_chat_id', 'max_user_id', 'BOT_NO_SEND'] as $col) {
            if (Database::columnExists('base_users', $col)) {
                $pdo->exec('ALTER TABLE `base_users` DROP COLUMN `' . $col . '`');
                Database::clearSchemaCache();
            }
        }
    }

    // Clients: base_clients → BOT_SOURCE=1
    if (Database::tableExists('base_clients')
        && (Database::columnExists('base_clients', 'telegram_chat_id')
            || Database::columnExists('base_clients', 'max_user_id'))
    ) {
        $hasTg = Database::columnExists('base_clients', 'telegram_chat_id');
        $hasMax = Database::columnExists('base_clients', 'max_user_id');
        $tgExpr = $hasTg ? 'telegram_chat_id' : 'NULL';
        $maxExpr = $hasMax ? 'max_user_id' : 'NULL';
        $where = [];
        if ($hasTg) {
            $where[] = '(telegram_chat_id IS NOT NULL AND telegram_chat_id <> 0)';
        }
        if ($hasMax) {
            $where[] = '(max_user_id IS NOT NULL AND max_user_id <> 0)';
        }
        if ($where !== []) {
            $pdo->exec(
                'INSERT INTO base_bot_links
                 (BOT_SOURCE, ID_ENTITY, telegram_chat_id, max_user_id, BOT_NO_SEND, DTCREATE, DTUPDATE)
                 SELECT 1, id, ' . $tgExpr . ', ' . $maxExpr . ', 0, ' . $now . ', ' . $now . '
                 FROM base_clients
                 WHERE ' . implode(' OR ', $where) . '
                 ON DUPLICATE KEY UPDATE
                   telegram_chat_id = COALESCE(VALUES(telegram_chat_id), base_bot_links.telegram_chat_id),
                   max_user_id = COALESCE(VALUES(max_user_id), base_bot_links.max_user_id),
                   DTUPDATE = VALUES(DTUPDATE)'
            );
        }
        foreach (['telegram_chat_id', 'max_user_id'] as $col) {
            if (Database::columnExists('base_clients', $col)) {
                $pdo->exec('ALTER TABLE `base_clients` DROP COLUMN `' . $col . '`');
                Database::clearSchemaCache();
            }
        }
    }
};
