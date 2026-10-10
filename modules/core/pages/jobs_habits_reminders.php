<?php
declare(strict_types=1);

/**
 * Shared-hosting cron twin: enqueue due my_tasks habit reminders.
 * Auth: X-FreeSaaS-Agent-Token (same as /agent/*).
 * Drain queue separately: POST /bots/sends/work
 */
AgentAuth::assertToken();

$simulate = !empty($_GET['simulate']) || !empty($_POST['simulate']);
$result = MyTasksHabits::cronSendDueReminders($simulate);
AgentHttp::emit([
    'ok' => true,
    'job' => 'habits-reminders',
    'ymd' => $result['ymd'],
    'checked' => $result['checked'],
    'sent' => $result['sent'],
    'simulate' => $simulate,
    'log' => $result['log'],
]);
