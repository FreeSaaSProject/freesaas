<?php
declare(strict_types=1);

/**
 * Upgrade legacy elos63 fit/health tables to FreeSaaS ID_USER ownership.
 *
 * CREATE IF NOT EXISTS in 001 is a no-op when oper tables already exist with ID_OPERATOR
 * (and catalogs without an owner column) — that caused 42S22 Unknown column 'ID_USER'.
 */
return static function (PDO $pdo): void {
    $ownerFromOperator = [
        'base_fit_foodlogs',
        'base_fitlogs',
        'base_fit_targets',
        'base_fit_weightlogs',
        'base_healths_logs',
        'base_healths_recipes',
        'base_fit_recipes',
        'base_fit_menus',
        'base_fit_cook_sessions',
        'base_fit_shopping_lists',
    ];

    foreach ($ownerFromOperator as $table) {
        if (!Database::tableExists($table)) {
            continue;
        }
        if (!Database::columnExists($table, 'ID_USER')) {
            $after = Database::columnExists($table, 'ID') ? 'AFTER ID' : '';
            $pdo->exec(
                'ALTER TABLE `' . str_replace('`', '``', $table) . '`
                 ADD COLUMN `ID_USER` INT UNSIGNED NOT NULL DEFAULT 0' . ($after !== '' ? ' ' . $after : '')
            );
            Database::clearSchemaCache();
        }
        if (Database::columnExists($table, 'ID_OPERATOR') && Database::columnExists($table, 'ID_USER')) {
            $pdo->exec(
                'UPDATE `' . str_replace('`', '``', $table) . '`
                 SET ID_USER = ID_OPERATOR
                 WHERE ID_USER = 0 AND ID_OPERATOR > 0'
            );
        }
    }

    // Catalogs were global in oper — add ID_USER and attribute from log usage.
    $catalogs = [
        'base_fit_foods' => [
            'log' => 'base_fit_foodlogs',
            'fk' => 'ID_FOOD',
            'ownerCol' => 'ID_USER',
            'legacyOwner' => 'ID_OPERATOR',
        ],
        'base_healths_medicines' => [
            'log' => 'base_healths_logs',
            'fk' => 'ID_MEDICINE',
            'ownerCol' => 'ID_USER',
            'legacyOwner' => 'ID_OPERATOR',
        ],
        'base_healths_symptoms' => [
            'log' => 'base_healths_logs',
            'fk' => 'ID_SYMPTOM',
            'ownerCol' => 'ID_USER',
            'legacyOwner' => 'ID_OPERATOR',
        ],
    ];

    foreach ($catalogs as $table => $meta) {
        if (!Database::tableExists($table)) {
            continue;
        }
        if (!Database::columnExists($table, 'ID_USER')) {
            $idxMap = [
                'base_fit_foods' => 'idx_fit_foods_user',
                'base_healths_medicines' => 'idx_healths_medicines_user',
                'base_healths_symptoms' => 'idx_healths_symptoms_user',
            ];
            $idx = $idxMap[$table] ?? 'idx_wellness_user';
            $pdo->exec(
                'ALTER TABLE `' . str_replace('`', '``', $table) . '`
                 ADD COLUMN `ID_USER` INT UNSIGNED NOT NULL DEFAULT 0 AFTER ID,
                 ADD KEY `' . $idx . '` (ID_USER)'
            );
            Database::clearSchemaCache();
        }

        $log = (string)$meta['log'];
        if (!Database::tableExists($log)) {
            continue;
        }
        $fk = (string)$meta['fk'];
        $ownerExpr = Database::columnExists($log, 'ID_USER')
            ? 'l.ID_USER'
            : (Database::columnExists($log, 'ID_OPERATOR') ? 'l.ID_OPERATOR' : null);
        if ($ownerExpr === null) {
            continue;
        }
        // Prefer earliest owner who logged this catalog row.
        $pdo->exec(
            'UPDATE `' . str_replace('`', '``', $table) . '` c
             INNER JOIN (
                SELECT `' . str_replace('`', '``', $fk) . '` AS cid, MIN(' . $ownerExpr . ') AS uid
                FROM `' . str_replace('`', '``', $log) . '` l
                WHERE ' . $ownerExpr . ' > 0 AND `' . str_replace('`', '``', $fk) . '` > 0
                GROUP BY `' . str_replace('`', '``', $fk) . '`
             ) x ON x.cid = c.ID
             SET c.ID_USER = x.uid
             WHERE c.ID_USER = 0'
        );
    }

    // Orphan catalog rows (never logged): assign to smallest staff user id if any.
    $fallbackUser = 0;
    if (Database::tableExists('base_users')) {
        $fallbackUser = (int)$pdo->query('SELECT MIN(ID) FROM base_users WHERE ID > 0')->fetchColumn();
    }
    if ($fallbackUser > 0) {
        foreach (array_keys($catalogs) as $table) {
            if (!Database::tableExists($table) || !Database::columnExists($table, 'ID_USER')) {
                continue;
            }
            $st = $pdo->prepare(
                'UPDATE `' . str_replace('`', '``', $table) . '` SET ID_USER = ? WHERE ID_USER = 0'
            );
            $st->execute([$fallbackUser]);
        }
    }

    // Weight: oper had UNIQUE(DT); FreeSaaS needs per-user uniqueness.
    if (Database::tableExists('base_fit_weightlogs')) {
        $st = $pdo->prepare(
            'SELECT INDEX_NAME FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
               AND NON_UNIQUE = 0 AND COLUMN_NAME = ? AND INDEX_NAME <> \'PRIMARY\'
             LIMIT 1'
        );
        $st->execute(['base_fit_weightlogs', 'DT']);
        $legacyUnique = (string)($st->fetchColumn() ?: '');
        if ($legacyUnique !== '' && $legacyUnique !== 'uq_fit_weightlogs_user_dt') {
            // Only drop if index is solely on DT (legacy weight1.php).
            $cols = $pdo->prepare(
                'SELECT COLUMN_NAME FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?
                 ORDER BY SEQ_IN_INDEX'
            );
            $cols->execute(['base_fit_weightlogs', $legacyUnique]);
            $colList = $cols->fetchAll(PDO::FETCH_COLUMN) ?: [];
            if ($colList === ['DT']) {
                $pdo->exec(
                    'ALTER TABLE base_fit_weightlogs DROP INDEX `' . str_replace('`', '``', $legacyUnique) . '`'
                );
            }
        }
        $st2 = $pdo->prepare(
            'SELECT 1 FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
               AND INDEX_NAME = ? LIMIT 1'
        );
        $st2->execute(['base_fit_weightlogs', 'uq_fit_weightlogs_user_dt']);
        if (!$st2->fetchColumn() && Database::columnExists('base_fit_weightlogs', 'ID_USER')) {
            // Dedupe same user+day keeping max ID before unique index.
            $pdo->exec(
                'DELETE w1 FROM base_fit_weightlogs w1
                 INNER JOIN base_fit_weightlogs w2
                   ON w1.ID_USER = w2.ID_USER AND w1.DT = w2.DT AND w1.ID < w2.ID'
            );
            $pdo->exec(
                'ALTER TABLE base_fit_weightlogs
                 ADD UNIQUE KEY uq_fit_weightlogs_user_dt (ID_USER, DT)'
            );
        }
    }

    Database::clearSchemaCache();
};
