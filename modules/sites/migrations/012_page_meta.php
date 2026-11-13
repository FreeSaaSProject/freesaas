<?php
declare(strict_types=1);

/**
 * Per-article SEO: META_TITLE / META_DESCR (same naming as base_promopages). Empty = page name / site meta.
 *
 * @return callable(PDO):void
 */
return static function (PDO $pdo): void {
    if (!Database::tableExists('base_page_categories')) {
        return;
    }
    if (!Database::columnExists('base_page_categories', 'META_TITLE')) {
        $pdo->exec(
            "ALTER TABLE base_page_categories
             ADD COLUMN META_TITLE VARCHAR(255) NOT NULL DEFAULT ''"
        );
    }
    if (!Database::columnExists('base_page_categories', 'META_DESCR')) {
        $pdo->exec(
            "ALTER TABLE base_page_categories
             ADD COLUMN META_DESCR VARCHAR(512) NOT NULL DEFAULT ''"
        );
    }
};
