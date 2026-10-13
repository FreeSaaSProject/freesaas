<?php
declare(strict_types=1);

/**
 * TEMP (phpshop cutoff): staff NFC from phpshop_shopusers.1C_BRACELET + ID_USER
 * → base_staff_bracelets. Idempotent. Delete this migrator (+ module.json entry +
 * lang keys) after phpshop_shopusers is dropped — not needed at runtime.
 */
return static function (PDO $pdo, array $spec): array {
    if (!Database::tableExists('base_staff_bracelets')) {
        return [
            'ok' => false,
            'inserted' => 0,
            'updated' => 0,
            'skipped' => 0,
            'message' => 'base_staff_bracelets missing — enable terminals / run schema migrations first',
        ];
    }
    if (!Database::tableExists('phpshop_shopusers')
        || !Database::columnExists('phpshop_shopusers', '1C_BRACELET')
        || !Database::columnExists('phpshop_shopusers', 'ID_USER')
    ) {
        return [
            'ok' => true,
            'inserted' => 0,
            'updated' => 0,
            'skipped' => 0,
            'message' => 'phpshop_shopusers.1C_BRACELET/ID_USER missing — nothing to import',
        ];
    }

    if (!class_exists(StaffBraceletsRepository::class, false)) {
        require_once dirname(__DIR__) . '/lib/StaffBraceletsRepository.php';
    }

    $rows = $pdo->query(
        'SELECT ID_USER AS user_id, `1C_BRACELET` AS uid
         FROM phpshop_shopusers
         WHERE `1C_BRACELET` IS NOT NULL
           AND TRIM(`1C_BRACELET`) <> \'\'
           AND ID_USER IS NOT NULL
           AND ID_USER > 0'
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $inserted = 0;
    $updated = 0;
    $skipped = 0;
    $seenUid = [];

    foreach ($rows as $r) {
        $userId = (int)($r['user_id'] ?? 0);
        $uid = trim((string)($r['uid'] ?? ''));
        if ($userId < 1 || $uid === '') {
            $skipped++;
            continue;
        }
        if (strlen($uid) > 32) {
            $skipped++;
            continue;
        }
        // Last row wins for duplicate UIDs in source; skip if we already wrote this UID this run
        // to a different user (setForUser steals UID anyway — prefer first non-empty unique).
        $uidKey = strtoupper($uid);
        if (isset($seenUid[$uidKey]) && $seenUid[$uidKey] !== $userId) {
            $skipped++;
            continue;
        }
        $seenUid[$uidKey] = $userId;

        $before = StaffBraceletsRepository::findByUserId($userId);
        try {
            StaffBraceletsRepository::setForUser($userId, $uid);
        } catch (Throwable $e) {
            $skipped++;
            continue;
        }
        if ($before === null) {
            $inserted++;
        } elseif ((string)$before['uid'] !== $uid) {
            $updated++;
        }
        // identical uid → count as neither (idempotent no-op)
    }

    return [
        'ok' => true,
        'inserted' => $inserted,
        'updated' => $updated,
        'skipped' => $skipped,
        'message' => "staff bracelets upserted (inserted≈{$inserted}, updated≈{$updated}, skipped={$skipped})",
    ];
};
