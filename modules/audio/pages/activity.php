<?php
declare(strict_types=1);

/**
 * GET /audio/activity — day summary by employee and hour (agent op audio.activity.summary).
 * See lib/ActivitySummary.php for definitions and paging.
 */
$res = ActivitySummary::run($_GET);
if (empty($res['ok'])) {
    AgentHttp::error((string)($res['error'] ?? ''), 400);
    return;
}
AgentHttp::emit($res);
