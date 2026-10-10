<?php
declare(strict_types=1);

/**
 * Agent-token read twin for cron registry + recent runs (no session).
 */
AgentAuth::assertToken();

$host = (string)($_SERVER['HTTP_HOST'] ?? 'example.com');
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';

AgentHttp::emit([
    'ok' => true,
    'jobs' => Cron::catalogWithState(),
    'runs' => Cron::recentRuns(40),
    'registered' => array_keys(Cron::registered()),
    'tables_ready' => Cron::tablesReady(),
    'stale_hint' => Cron::staleHint(),
    'tick_url' => $scheme . '://' . $host . '/cron/tick',
    'agent_token_configured' => AgentAuth::isConfigured(),
]);
