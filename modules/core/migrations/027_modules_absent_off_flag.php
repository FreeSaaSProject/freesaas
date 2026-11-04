<?php
declare(strict_types=1);

/**
 * Ensure app.modules_absent_off=1 for installs that applied an older 025 without the flag.
 *
 * If the flag is already on (normal case after 025), pinEffectiveModules runs in fail_closed
 * mode: absent on-disk modules get explicit =0 only — never =1. That prevents new packs
 * (backups, recons, …) from booting without activate / accept_risk.
 *
 * Write failures abort the migration.
 *
 * @return callable(PDO):void
 */
return static function (PDO $pdo): void {
    $report = Config::pinEffectiveModules();
    $n = count($report['pinned']);
    $mode = (string)($report['mode'] ?? '');
    if ($n > 0) {
        error_log(
            '[core/027] mode=' . $mode . ' pinned ' . $n . ' module(s): '
            . implode(',', array_keys($report['pinned']))
        );
    }
    error_log('[core/027] app.modules_absent_off ensured (mode=' . $mode . ')');
};
