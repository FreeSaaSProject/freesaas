<?php
declare(strict_types=1);

/**
 * Widen dictionary DESCR for LLM intent blurbs (coach + human notes).
 */
return static function (PDO $pdo): void {
    if (!class_exists('Database', false) || !Database::tableExists('base_records_dictonarys')) {
        return;
    }
    if (!Database::columnExists('base_records_dictonarys', 'DESCR')) {
        return;
    }
    $pdo->exec(
        "ALTER TABLE base_records_dictonarys
         MODIFY COLUMN DESCR VARCHAR(200) NOT NULL DEFAULT ''"
    );
};
