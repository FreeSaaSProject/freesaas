<?php
declare(strict_types=1);

/**
 * First flip to fail-closed module defaults (only meaningful when app.modules_absent_off is unset):
 * pin on-disk modules missing [modules] keys to =1 (legacy absent = on), then set the flag.
 *
 * Until this runs, Config::moduleEnabled keeps legacy absent=on so a code deploy cannot turn off
 * working modules before migrate. After the flag is set, pinEffectiveModules never auto-enables
 * (see 027 / Config::pinEffectiveModules fail_closed mode).
 *
 * Write failures abort the migration (no silent log-only swallow).
 *
 * @return callable(PDO):void
 */
return static function (PDO $pdo): void {
    $report = Config::pinEffectiveModules();
    $n = count($report['pinned']);
    $mode = (string)($report['mode'] ?? '');
    if ($n > 0) {
        error_log(
            '[core/025] mode=' . $mode . ' pinned ' . $n . ' module(s): '
            . implode(',', array_keys($report['pinned']))
        );
    }
    error_log('[core/025] app.modules_absent_off=1');
};
