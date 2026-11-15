<?php
declare(strict_types=1);

/**
 * Explicit doctor confirmation of a filled document (separate from "ready" = required fields filled).
 */
return static function (PDO $pdo): void {
    $cols = static function (string $table) use ($pdo): array {
        $st = $pdo->query('SHOW COLUMNS FROM ' . $table);
        return array_map(static fn(array $r): string => (string)$r['Field'], $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : []);
    };
    $have = $cols('base_doc_instances');
    if (!in_array('DT_CONFIRMED', $have, true)) {
        $pdo->exec('ALTER TABLE base_doc_instances ADD COLUMN DT_CONFIRMED INT UNSIGNED NOT NULL DEFAULT 0 AFTER DT_SIGNED');
    }
    if (!in_array('ID_USER_CONFIRMED', $have, true)) {
        $pdo->exec('ALTER TABLE base_doc_instances ADD COLUMN ID_USER_CONFIRMED INT UNSIGNED NOT NULL DEFAULT 0 AFTER DT_CONFIRMED');
    }
};
