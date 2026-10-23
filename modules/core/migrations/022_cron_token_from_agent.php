<?php
declare(strict_types=1);

/**
 * Seed app.cron_token from app.agent_token on existing installs so host crontabs
 * keep working and the two secrets can be rotated independently afterwards.
 * Never throws: an unwritable cfg/ must not stop the core migration chain
 * (/cron/tick still accepts agent_token as a legacy fallback).
 *
 * @return callable(PDO):void
 */
return static function (PDO $pdo): void {
    try {
        $cron = trim((string)Config::get('app', 'cron_token', ''));
        if ($cron !== '' && $cron !== 'CHANGE_ME') {
            return;
        }
        $agent = trim((string)Config::get('app', 'agent_token', ''));
        if ($agent === '' || $agent === 'CHANGE_ME') {
            return;
        }
        Config::set('app', 'cron_token', $agent);
    } catch (Throwable $e) {
        error_log('[core/022] cron_token not copied: ' . $e->getMessage());
    }
};
