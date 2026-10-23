<?php
declare(strict_types=1);

/**
 * Formerly added work_hour_* columns to base_system. Hours now live in
 * cfg/modules/calendar.ini (see 006_hours_from_base_system). Kept as a ledger no-op.
 */
return static function (PDO $pdo): void {
};
