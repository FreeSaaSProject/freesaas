<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    AgentHttp::error('Method not allowed', 405);
}

$userId = Auth::userId();
if ($userId < 1) {
    AgentHttp::error('Unauthorized', 401);
}

$raw = function_exists('request_raw_body') ? request_raw_body() : '';
$body = $raw !== '' ? json_decode($raw, true) : null;
if (!is_array($body)) {
    $body = $_POST;
}

$rating = trim((string)($body['rating'] ?? ''));
$reasons = $body['reasons'] ?? [];
if (!is_array($reasons)) {
    $reasons = [];
}
$comment = trim((string)($body['comment'] ?? ''));
$messages = $body['messages'] ?? [];
if (!is_array($messages)) {
    $messages = [];
}

if ($rating === AssistantFeedbackStore::RATING_BAD && $reasons === [] && $comment === '') {
    AgentHttp::error(t('assistant.feedback.err.need_reason'), 400);
}

$saved = AssistantFeedbackStore::save([
    'rating' => $rating,
    'reasons' => $reasons,
    'comment' => $comment,
    'messages' => $messages,
    'user_id' => $userId,
    'locale' => class_exists('I18n', false) ? I18n::locale() : '',
]);

if (empty($saved['ok'])) {
    $errCode = (string)($saved['error'] ?? '');
    $msg = $errCode === 'empty context'
        ? t('assistant.feedback.err.empty_context')
        : t('assistant.feedback.err.save');
    AgentHttp::error($msg !== '' ? $msg : $errCode, 500);
}

AgentHttp::emit([
    'ok' => true,
    'id' => (string)($saved['id'] ?? ''),
    'message' => t('assistant.feedback.thanks'),
]);
