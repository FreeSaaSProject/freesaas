<?php
declare(strict_types=1);

/**
 * Safe flip to fail-closed module defaults:
 * 1) Pin every on-disk product module missing a [modules] key to =1 (legacy absent = on).
 * 2) Set app.modules_absent_off=1 so future absent keys stay disabled (new packs / overlays).
 *
 * Until this runs, Config::moduleEnabled keeps legacy absent=on so a code deploy cannot
 * turn off working modules before migrate. After this runs, only explicit =1 stays on.
 *
 * @return callable(PDO):void
 */
return static function (PDO $pdo): void {
    try {
        $report = Config::pinEffectiveModules();
        $n = count($report['pinned']);
        if ($n > 0) {
            error_log('[core/025] pinned ' . $n . ' module(s) to [modules]=1: ' . implode(',', array_keys($report['pinned'])));
        }
        error_log('[core/025] app.modules_absent_off=1 (absent keys now disabled)');
    } catch (Throwable $e) {
        error_log('[core/025] pinEffectiveModules failed: ' . $e->getMessage());
    }
};
