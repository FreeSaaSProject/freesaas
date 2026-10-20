<?php
declare(strict_types=1);

/**
 * Nazop used to force ID_TABLE=1 (о1) on every new shift. Tables are assigned
 * only for today via nazcab — wipe non-zero tables on tomorrow+ (idempotent).
 */
return static function (PDO $pdo): void {
    if (!Database::tableExists('base_workweeks')) {
        return;
    }
    $tomorrow = strtotime('tomorrow 00:00:00');
    if ($tomorrow === false || $tomorrow < 1) {
        return;
    }
    $st = $pdo->prepare(
        'UPDATE base_workweeks SET ID_TABLE = 0 WHERE DT >= ? AND ID_TABLE <> 0'
    );
    $st->execute([$tomorrow]);
};
