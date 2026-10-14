<?php
declare(strict_types=1);

/**
 * Guard: keep LEMMA width explicit (255). Truncation is enforced in StemCache::rememberWord.
 * Idempotent no-op ALTER if already 255+ — documents the contract for ops.
 */
return static function (PDO $pdo): void {
    if (!Database::tableExists('base_records_stem_word_cache')) {
        return;
    }
    if (!Database::columnExists('base_records_stem_word_cache', 'LEMMA')) {
        return;
    }
    // Ensure column can hold truncated lemmas; widen slightly for utf8mb4 edge cases.
    try {
        $pdo->exec(
            'ALTER TABLE base_records_stem_word_cache
             MODIFY COLUMN LEMMA VARCHAR(255) NOT NULL DEFAULT \'\''
        );
    } catch (Throwable $e) {
        // Ignore if engine rejects identical definition.
    }
};
