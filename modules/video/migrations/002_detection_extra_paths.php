<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Track sibling frame paths (raw + annotated) so retention can delete both.
 */
return static function (PDO $pdo): void {
    if (Database::columnExists('base_video_detections', 'EXTRA_PATHS_JSON')) {
        return;
    }
    $pdo->exec(
        'ALTER TABLE base_video_detections
         ADD COLUMN EXTRA_PATHS_JSON MEDIUMTEXT NULL AFTER FRAME_PATH'
    );
};
