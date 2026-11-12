<?php
declare(strict_types=1);

/**
 * Push ASR workers moved to the shared edge host registry (core base_edge_hosts).
 * Same copy as core 030 for installs where audio_pipeline 008 ran after it; INSERT IGNORE keeps it idempotent.
 */
return static function (PDO $pdo): void {
    if (!Database::tableExists('base_edge_hosts') || !Database::tableExists('base_asr_push_workers')) {
        return;
    }
    $pdo->exec(
        "INSERT IGNORE INTO base_edge_hosts
            (ID, NAME, TRANSPORT, BASE_URL, KEY_ID, SECRET, SECRET_NEXT, PIN_SHA256, ENABLED, PLUGINS_JSON,
             API_VERSION, LAST_SEEN, LAST_STATUS, LAST_ERROR, FAILS, BACKOFF_UNTIL, DT_CREATED)
         SELECT ID, NAME, 'inbound', BASE_URL, 'owner', SECRET, SECRET_NEXT, PIN_SHA256, ENABLED,
                CONCAT('{\"asr\":{\"enabled\":', IF(ENABLED = 1, 'true', 'false'), ',\"max_inflight\":', MAX_INFLIGHT, '}}'),
                1, LAST_SEEN,
                IF(LAST_STATUS IS NULL OR LAST_STATUS = '', NULL, CONCAT('{\"asr\":', LAST_STATUS, '}')),
                LAST_ERROR, FAILS, BACKOFF_UNTIL, DT_CREATED
           FROM base_asr_push_workers"
    );
};
