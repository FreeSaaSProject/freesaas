<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

$message = '';
$error = '';
$deadlinePenalty = ContractsRepository::deadlinePenaltyEnabled();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        // HTML form always posts the key (hidden 0 + checkbox); agents may omit → leave unchanged.
        if (array_key_exists('deadline_penalty', $_POST)) {
            Config::set('contracts', 'deadline_penalty', !empty($_POST['deadline_penalty']) ? '1' : '0');
        }
        $deadlinePenalty = ContractsRepository::deadlinePenaltyEnabled();
        $message = t('core.common.saved');
        if ($wantsJson) {
            AgentHttp::emit([
                'ok' => true,
                'saved' => true,
                'deadline_penalty' => $deadlinePenalty,
            ]);
            return;
        }
    } catch (Throwable $e) {
        AgentHttp::rethrowIfCapture($e);
        if ($wantsJson) {
            AgentHttp::error($e->getMessage(), 400);
            return;
        }
        $error = $e->getMessage();
    }
}

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'deadline_penalty' => $deadlinePenalty,
    ]);
    return;
}

View::render('settings', [
    'title' => t('contracts.menu.settings'),
    'message' => $message,
    'error' => $error,
    'deadlinePenalty' => $deadlinePenalty,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('contracts.menu.list'), 'href' => '/contracts'],
        ['label' => t('contracts.menu.settings'), 'current' => true],
    ]),
], 'layout', 'contracts');
