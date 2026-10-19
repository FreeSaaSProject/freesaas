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

$seedQ = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
if (function_exists('mb_substr')) {
    $seedQ = mb_substr($seedQ, 0, 2000);
} else {
    $seedQ = substr($seedQ, 0, 2000);
}

$menuLabels = [];
foreach (ModuleManager::menuItems() as $item) {
    $path = parse_url((string)($item['path'] ?? ''), PHP_URL_PATH);
    $path = is_string($path) && $path !== '' ? $path : (string)($item['path'] ?? '');
    $path = rtrim($path, '/') ?: '/';
    $title = trim((string)($item['title'] ?? ''));
    if ($path === '' || $title === '') {
        continue;
    }
    // Prefer shortest path wins only if empty — keep first (usually best menu match).
    if (!isset($menuLabels[$path])) {
        $menuLabels[$path] = $title;
    }
}

$an = static function (string $key, array $replace = []): string {
    return class_exists('AssistantConfig', false)
        ? AssistantConfig::t($key, $replace)
        : t($key, $replace);
};

View::render('chat', [
    'title' => $an('assistant.chat.title'),
    'tools' => $tools,
    'history' => $history,
    'seed_q' => $seedQ,
    'admin_base' => class_exists('AssistantLinks', false) ? AssistantLinks::base() : '',
    'menu_labels' => $menuLabels,
    'main_flush' => true,
    'i18n' => [
        'send' => t('assistant.chat.send'),
        'thinking' => t('assistant.chat.thinking'),
        'you' => t('assistant.chat.you'),
        'assistant' => $an('assistant.chat.assistant'),
        'error' => t('assistant.chat.error'),
        'empty' => t('assistant.chat.empty'),
        'confirm' => t('assistant.confirm.btn'),
        'cancel' => t('assistant.confirm.cancel'),
        'confirm_all' => t('assistant.confirm.btn_all'),
        'cancel_all' => t('assistant.confirm.cancel_all'),
        'pending' => t('assistant.confirm.pending', ['summary' => '{summary}']),
        'pending_n' => t('assistant.confirm.pending_n', ['n' => '{n}']),
        'confirm_done' => t('assistant.confirm.done'),
        'confirm_cancelled' => t('assistant.confirm.cancelled'),
        'show_on_screen' => t('assistant.ui.show_on_screen'),
        'mirror_hint' => t('assistant.ui.mirror_hint'),
        'clear' => t('assistant.chat.clear'),
        'cleared' => t('assistant.chat.cleared'),
        'tools' => t('assistant.tool.trace'),
        'tool_pending' => t('assistant.tool.pending'),
        'tool_forbidden' => t('assistant.tool.forbidden'),
        'fb_rate' => t('assistant.feedback.rate'),
        'fb_good' => t('assistant.feedback.good'),
        'fb_bad' => t('assistant.feedback.bad'),
        'fb_thanks' => t('assistant.feedback.thanks'),
        'fb_need_reason' => t('assistant.feedback.err.need_reason'),
        'fb_err' => t('assistant.feedback.err.save'),
        'fb_reasons' => [
            'inaccurate' => t('assistant.feedback.reason.inaccurate'),
            'ignored_instructions' => t('assistant.feedback.reason.ignored_instructions'),
            'off_topic' => t('assistant.feedback.reason.off_topic'),
            'lost_context' => t('assistant.feedback.reason.lost_context'),
            'slow_or_errors' => t('assistant.feedback.reason.slow_or_errors'),
            'safety_legal' => t('assistant.feedback.reason.safety_legal'),
            'other' => t('assistant.feedback.reason.other'),
        ],
    ],
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => class_exists('AssistantConfig', false)
            ? AssistantConfig::displayName()
            : $an('assistant.menu.chat'), 'current' => true],
    ]),
], 'layout', 'assistant');
