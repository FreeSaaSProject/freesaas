<?php
declare(strict_types=1);

/**
 * ASR correction links on dictionary keys: a variant key points to the canonical
 * (correct) key. Transcripts are not rewritten; display/LLM feeds substitute at read time.
 *
 *  ID_REPLACE      approved canonical key id (0 = no replacement)
 *  REPLACE_SUGGEST temporary free-text proposal when no matching canonical key exists yet
 *  REPLACE_STATE   '' | pending | suggested | approved | keep | rejected
 */
return static function (PDO $pdo): void {
    if (!class_exists('Database', false) || !Database::tableExists('base_records_keys')) {
        return;
    }
    $cols = [
        'ID_REPLACE' => 'ID_REPLACE INT UNSIGNED NOT NULL DEFAULT 0',
        'REPLACE_SUGGEST' => "REPLACE_SUGGEST VARCHAR(255) NOT NULL DEFAULT ''",
        'REPLACE_STATE' => "REPLACE_STATE VARCHAR(10) NOT NULL DEFAULT ''",
    ];
    foreach ($cols as $col => $ddl) {
        if (!Database::columnExists('base_records_keys', $col)) {
            $pdo->exec('ALTER TABLE base_records_keys ADD COLUMN ' . $ddl);
        }
    }
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'
    );
    $st->execute(['base_records_keys', 'ID_REPLACE']);
    if ((int)$st->fetchColumn() < 1) {
        $pdo->exec('ALTER TABLE base_records_keys ADD KEY ID_REPLACE (ID_REPLACE)');
    }
    $st->execute(['base_records_keys', 'REPLACE_STATE']);
    if ((int)$st->fetchColumn() < 1) {
        $pdo->exec('ALTER TABLE base_records_keys ADD KEY REPLACE_STATE (REPLACE_STATE)');
    }
};
