<?php
declare(strict_types=1);

/**
 * Indexes for contract notices / list filters on base_comments.
 */
return static function (PDO $pdo): void {
    $exists = static function (string $name) use ($pdo): bool {
        $st = $pdo->prepare(
            'SELECT 1 FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1'
        );
        $st->execute(['base_comments', $name]);
        return (bool)$st->fetchColumn();
    };

    if (!$exists('idx_contracts_offer')) {
        $pdo->exec(
            'ALTER TABLE base_comments
             ADD INDEX idx_contracts_offer (PRICE_OFFER, DT_OFFER_ACCEPT, DT_WORK_ACCEPT, DT_FAIL)'
        );
    }
    if (!$exists('idx_contracts_user_offer')) {
        $pdo->exec(
            'ALTER TABLE base_comments
             ADD INDEX idx_contracts_user_offer (ID_USER, PRICE_OFFER, DT_OFFER_ACCEPT)'
        );
    }
    if (!$exists('idx_contracts_acceptor')) {
        $pdo->exec(
            'ALTER TABLE base_comments
             ADD INDEX idx_contracts_acceptor (ID_ACCEPTOR, PRICE_OFFER, DT_OFFER_ACCEPT)'
        );
    }
    if (!$exists('idx_contracts_object_offer')) {
        $pdo->exec(
            'ALTER TABLE base_comments
             ADD INDEX idx_contracts_object_offer (ID_OBJECT, PRICE_OFFER, DT_OFFER_ACCEPT, DT_WORK_ACCEPT)'
        );
    }

    // Speeds hasPayment() lookups when payments module exists
    $st = $pdo->prepare(
        'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
    );
    $st->execute(['base_payments']);
    if ($st->fetchColumn()) {
        $stIdx = $pdo->prepare(
            'SELECT 1 FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1'
        );
        $stIdx->execute(['base_payments', 'idx_payments_comment']);
        if (!$stIdx->fetchColumn()) {
            try {
                $pdo->exec('ALTER TABLE base_payments ADD INDEX idx_payments_comment (ID_COMMENT)');
            } catch (Throwable $e) {
                // ignore
            }
        }
    }
};
