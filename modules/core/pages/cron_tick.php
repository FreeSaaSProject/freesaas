<?php
declare(strict_types=1);

/**
 * Host crontab entrypoint: GET/POST /cron/tick + X-FreeSaaS-Agent-Token
 * (app.cron_token, or legacy app.agent_token).
 */
AgentAuth::assertCronToken();

$only = trim((string)($_GET['job'] ?? $_POST['job'] ?? ''));
$force = !empty($_GET['force']) || !empty($_POST['force']);
$result = Cron::tick($force, $only !== '' ? $only : null);

if (!empty($result['blocked'])) {
    http_response_code(409);
}
header('Content-Type: application/json; charset=utf-8');
echo json_encode($result, JSON_UNESCAPED_UNICODE);
