<?php
declare(strict_types=1);

/**
 * GET /audio/transcripts — batch cleaned transcripts (agent op audio.transcripts.batch).
 * See lib/TranscriptsBatch.php for selection, context and byte budget.
 */
$res = TranscriptsBatch::run($_GET);
if (empty($res['ok'])) {
    AgentHttp::error((string)($res['error'] ?? ''), 400);
    return;
}
AgentHttp::emit($res);
