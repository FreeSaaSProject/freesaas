<?php
declare(strict_types=1);

/**
 * Import TG/Max chat ids from legacy phpshop_users / phpshop_shopusers into base_bot_links.
 * Run after users/clients data migrators. Idempotent upsert by (BOT_SOURCE, ID_ENTITY).
 */
return static function (PDO $pdo, array $spec): array {
    if (!Database::tableExists('base_bot_links')) {
        return [
            'ok' => false,
            'message' => 'base_bot_links missing — enable bots / run schema migrations first',
            'inserted' => 0,
            'updated' => 0,
        ];
    }

    $now = time();
    $inserted = 0;
    $updated = 0;

    $upsert = static function (int $source, int $entityId, $tg, $max, int $noSend) use ($pdo, $now, &$inserted, &$updated): void {
        if ($entityId < 1) {
            return;
        }
        $tgVal = ($tg !== null && $tg !== '' && (string)$tg !== '0') ? (string)$tg : null;
        $maxVal = ($max !== null && $max !== '' && (string)$max !== '0') ? (string)$max : null;
        if ($tgVal === null && $maxVal === null && $noSend === 0) {
            return;
        }
        $check = $pdo->prepare(
            'SELECT ID FROM base_bot_links WHERE BOT_SOURCE = ? AND ID_ENTITY = ? LIMIT 1'
        );
        $check->execute([$source, $entityId]);
        $exists = (int)$check->fetchColumn() > 0;
        $st = $pdo->prepare(
            'INSERT INTO base_bot_links
             (BOT_SOURCE, ID_ENTITY, telegram_chat_id, max_user_id, BOT_NO_SEND, DTCREATE, DTUPDATE)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               telegram_chat_id = COALESCE(VALUES(telegram_chat_id), base_bot_links.telegram_chat_id),
               max_user_id = COALESCE(VALUES(max_user_id), base_bot_links.max_user_id),
               BOT_NO_SEND = GREATEST(VALUES(BOT_NO_SEND), base_bot_links.BOT_NO_SEND),
               DTUPDATE = VALUES(DTUPDATE)'
        );
        $st->execute([$source, $entityId, $tgVal, $maxVal, $noSend, $now, $now]);
        if ($exists) {
            $updated++;
        } else {
            $inserted++;
        }
    };

    // Staff
    if (Database::tableExists('phpshop_users')) {
        $hasTg = Database::columnExists('phpshop_users', 'telegram_chat_id');
        $hasMax = Database::columnExists('phpshop_users', 'max_user_id');
        $hasNo = Database::columnExists('phpshop_users', 'BOT_NO_SEND');
        if ($hasTg || $hasMax || $hasNo) {
            $cols = ['id'];
            if ($hasTg) {
                $cols[] = 'telegram_chat_id';
            }
            if ($hasMax) {
                $cols[] = 'max_user_id';
            }
            if ($hasNo) {
                $cols[] = 'BOT_NO_SEND';
            }
            $rows = $pdo->query('SELECT ' . implode(',', $cols) . ' FROM phpshop_users')->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as $r) {
                $upsert(
                    2,
                    (int)($r['id'] ?? 0),
                    $hasTg ? ($r['telegram_chat_id'] ?? null) : null,
                    $hasMax ? ($r['max_user_id'] ?? null) : null,
                    $hasNo ? (!empty($r['BOT_NO_SEND']) ? 1 : 0) : 0
                );
            }
        }
    }

    // Clients (shopusers)
    if (Database::tableExists('phpshop_shopusers')) {
        $hasTg = Database::columnExists('phpshop_shopusers', 'telegram_chat_id');
        $hasMax = Database::columnExists('phpshop_shopusers', 'max_user_id');
        if ($hasTg || $hasMax) {
            $cols = ['id'];
            if ($hasTg) {
                $cols[] = 'telegram_chat_id';
            }
            if ($hasMax) {
                $cols[] = 'max_user_id';
            }
            $rows = $pdo->query('SELECT ' . implode(',', $cols) . ' FROM phpshop_shopusers')->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as $r) {
                $upsert(
                    1,
                    (int)($r['id'] ?? 0),
                    $hasTg ? ($r['telegram_chat_id'] ?? null) : null,
                    $hasMax ? ($r['max_user_id'] ?? null) : null,
                    0
                );
            }
        }
    }

    return [
        'ok' => true,
        'inserted' => $inserted,
        'updated' => $updated,
        'message' => "bot links upserted (inserted≈{$inserted}, updated≈{$updated})",
    ];
};
