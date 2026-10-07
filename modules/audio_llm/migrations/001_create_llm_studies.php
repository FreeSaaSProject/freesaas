<?php
declare(strict_types=1);

/**
 * LLM studies for audio records (oper patches 487 + 488).
 */
return static function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_records_llm_studies (
            ID INT(11) NOT NULL AUTO_INCREMENT,
            TITLE VARCHAR(255) NOT NULL DEFAULT '',
            SELECTION_MODE ENUM('admin_range','record_ids') NOT NULL DEFAULT 'record_ids',
            ID_ADMIN INT(11) NOT NULL DEFAULT 0,
            DT_RANGE_START INT(11) NOT NULL DEFAULT 0,
            DT_RANGE_END INT(11) NOT NULL DEFAULT 0,
            RECORD_IDS_RAW TEXT NOT NULL,
            PROMPT TEXT NOT NULL,
            IS_CYCLIC TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=cyclic template',
            CYCLIC_ENABLED TINYINT(1) NOT NULL DEFAULT 0,
            TRIGGER_GOOD_ID INT(11) NOT NULL DEFAULT 0,
            AMOUNT_POSITIVE INT(11) NOT NULL DEFAULT 0,
            AMOUNT_NEGATIVE INT(11) NOT NULL DEFAULT 0,
            ID_CYCLIC_PARENT INT(11) NOT NULL DEFAULT 0,
            ID_PROCEDURE INT(11) NOT NULL DEFAULT 0,
            STATUS TINYINT(1) NOT NULL DEFAULT 0 COMMENT '0=pending,1=processing,2=done,3=error',
            TOTAL_RECORDS INT(11) NOT NULL DEFAULT 0,
            DONE_RECORDS INT(11) NOT NULL DEFAULT 0,
            MEANINGFUL_COUNT INT(11) NOT NULL DEFAULT 0,
            LLM_SUMMARY TEXT NOT NULL,
            DT_SUMMARY INT(11) NOT NULL DEFAULT 0,
            ID_CREATED_BY INT(11) NOT NULL DEFAULT 0,
            DT_CREATE INT(11) NOT NULL,
            DT_UPDATE INT(11) NOT NULL DEFAULT 0,
            DT_FINISHED INT(11) NOT NULL DEFAULT 0,
            PRIMARY KEY (ID),
            KEY idx_status_create (STATUS, DT_CREATE),
            KEY idx_cyclic_child (ID_CYCLIC_PARENT, ID_PROCEDURE),
            KEY idx_is_cyclic (IS_CYCLIC, CYCLIC_ENABLED)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_records_llm_study_results (
            ID INT(11) NOT NULL AUTO_INCREMENT,
            ID_STUDY INT(11) NOT NULL,
            ID_RECORD INT(11) NOT NULL,
            ID_ADMIN INT(11) NOT NULL DEFAULT 0,
            STATUS TINYINT(1) NOT NULL DEFAULT 0 COMMENT '0=pending,1=processing,2=done,3=error,4=skipped',
            RESULT_TEXT TEXT NOT NULL,
            ERROR_TEXT TEXT NOT NULL,
            DT_PROCESSED INT(11) NOT NULL DEFAULT 0,
            PRIMARY KEY (ID),
            KEY idx_study_status (ID_STUDY, STATUS),
            KEY idx_study_record (ID_STUDY, ID_RECORD)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
};
