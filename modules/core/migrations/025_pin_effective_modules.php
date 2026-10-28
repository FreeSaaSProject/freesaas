<?php
declare(strict_types=1);

/**
 * Before absent-[modules]-key = disabled: pin every on-disk product module that still
 * lacks an explicit key to =1 (legacy absent meant enabled). Modules already listed
 * keep their values. Safe to re-run (no-op when all keys exist).
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
    } catch (Throwable $e) {
        error_log('[core/025] pinEffectiveModules failed: ' . $e->getMessage());
    }
};
