<?php
declare(strict_types=1);

/**
 * Unique settlement rows per event/order kind (double-submit guard).
 * Index helps balance() filter on expires_at.
 */
return static function (PDO $pdo): void {
    $has = static function (string $index) use ($pdo): bool {
        $st = $pdo->query("SHOW INDEX FROM base_bonus_moves WHERE Key_name = " . $pdo->quote($index));
        return $st !== false && (bool)$st->fetch(PDO::FETCH_ASSOC);
    };

    // Drop duplicate settlement rows keeping the lowest id (pre-unique cleanup).
    $pdo->exec(
        'DELETE m FROM base_bonus_moves m
         INNER JOIN base_bonus_moves d
           ON d.ref_type = m.ref_type
          AND d.ref_id = m.ref_id
          AND d.kind = m.kind
          AND d.id < m.id
         WHERE m.ref_type IS NOT NULL
           AND m.ref_id IS NOT NULL
           AND m.kind IN (\'event_spend\', \'event_accrue\')'
    );

    if (!$has('uq_bonus_moves_ref_kind')) {
        $pdo->exec(
            'ALTER TABLE base_bonus_moves
             ADD UNIQUE KEY uq_bonus_moves_ref_kind (ref_type, ref_id, kind)'
        );
    }
    if (!$has('idx_bonus_moves_expires')) {
        $pdo->exec(
            'ALTER TABLE base_bonus_moves
             ADD KEY idx_bonus_moves_expires (client_id, expires_at, dismissed)'
        );
    }
};
