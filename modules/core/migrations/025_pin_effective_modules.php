<?php
declare(strict_types=1);

/**
 * Pin every on-disk product module missing a [modules] key to explicit =0 (fail-closed).
 * Do NOT auto-enable: new packs / beta modules must go through activate (+ accept_risk).
 * Modules already listed in cfg keep their values. Idempotent when all keys exist.
 *
 * @return callable(PDO):void
 */
return static function (PDO $pdo): void {
    try {
        $report = Config::pinEffectiveModules();
        $n = count($report['pinned']);
        if ($n > 0) {
            error_log('[core/025] pinned ' . $n . ' module(s) to [modules]=0: ' . implode(',', array_keys($report['pinned'])));
        }
    } catch (Throwable $e) {
        error_log('[core/025] pinEffectiveModules failed: ' . $e->getMessage());
    }
};
