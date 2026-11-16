<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Extra ICD codes seen on demomed visit payloads (atrophy / hypertonus).
 */

return static function (PDO $pdo): void {
    $rows = [
        ['L90.8', 'Other atrophic disorders of skin', 'L90'],
        ['L90.9', 'Atrophic disorder of skin, unspecified', 'L90'],
        ['R25.8', 'Other and unspecified abnormal involuntary movements', 'R25'],
        ['R25.0', 'Abnormal head movements', 'R25'],
        ['R25.1', 'Tremor, unspecified', 'R25'],
        ['R25.2', 'Cramp and spasm', 'R25'],
        ['R25.3', 'Fasciculation', 'R25'],
    ];
    $st = $pdo->prepare(
        'INSERT INTO base_egisz_icd10 (CODE, NAME, PARENT, ENABLED)
         VALUES (?, ?, ?, 1)
         ON DUPLICATE KEY UPDATE NAME = VALUES(NAME), PARENT = VALUES(PARENT), ENABLED = 1'
    );
    foreach ($rows as $r) {
        $st->execute([$r[0], $r[1], $r[2]]);
    }
};
