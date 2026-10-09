<?php
declare(strict_types=1);

$tools = [];
if (class_exists('AssistantTools', false)) {
    foreach (AssistantTools::availableOps() as $op) {
        $tools[] = [
            'id' => $op['id'],
            'side_effect' => $op['side_effect'],
            'description' => $op['description'],
        ];
    }
}

$history = [];
if (class_exists('AssistantDialog', false) && Auth::userId() > 0) {
    $history = AssistantDialog::getHistory(Auth::userId());
}

View::render('chat', [
    'title' => t('assistant.chat.title'),
    'tools' => $tools,
    'history' => $history,
    'i18n' => [
        'send' => t('assistant.chat.send'),
        'thinking' => t('assistant.chat.thinking'),
        'you' => t('assistant.chat.you'),
        'assistant' => t('assistant.chat.assistant'),
        'error' => t('assistant.chat.error'),
        'empty' => t('assistant.chat.empty'),
        'confirm' => t('assistant.confirm.btn'),
        'cancel' => t('assistant.confirm.cancel'),
        'pending' => t('assistant.confirm.pending', ['summary' => '{summary}']),
        'confirm_done' => t('assistant.confirm.done'),
        'confirm_cancelled' => t('assistant.confirm.cancelled'),
    ],
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('assistant.menu.chat'), 'current' => true],
    ]),
], 'layout', 'assistant');
