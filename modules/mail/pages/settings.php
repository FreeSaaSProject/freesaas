<?php
declare(strict_types=1);

$message = '';
$error = '';
$testResult = null;
$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

if ($isPost) {
    $action = (string)($_POST['action'] ?? 'save');
    try {
        if ($action === 'test') {
            $to = trim((string)($_POST['to'] ?? ''));
            if ($to === '') {
                throw new InvalidArgumentException(t('mail.err.recipient'));
            }
            $testResult = MailSend::sendTest($to, (string)($_POST['subject'] ?? ''), (string)($_POST['text'] ?? ''));
            if (!empty($testResult['ok'])) {
                $message = t('mail.settings.test_ok', ['to' => $to]);
            } else {
                $error = t('mail.settings.test_fail') . ': ' . (string)$testResult['error'];
            }
        } else {
            MailConfig::saveFromPost($_POST);
            $message = t('core.common.saved');
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$values = MailConfig::formValues();
$pending = MailSendsRepository::countPending();

if (AgentHttp::wantsJson()) {
    if ($isPost && $error !== '') {
        AgentHttp::error($error, 400, $testResult !== null ? ['test' => $testResult] : []);
    }
    AgentHttp::emit([
        'ok' => true,
        'message' => $message,
        'settings' => $values,
        'pending' => $pending,
        'test' => $testResult,
    ]);
}

View::render('settings', [
    'title' => t('mail.settings.title'),
    'message' => $message,
    'error' => $error,
    'values' => $values,
    'pending' => $pending,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('mail.menu.settings'), 'current' => true],
    ]),
], 'layout', 'mail');
