<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/AssistantConfig.php';
require_once __DIR__ . '/lib/AssistantPrefs.php';
require_once __DIR__ . '/lib/AssistantPageContext.php';
require_once __DIR__ . '/lib/PendingActionsRepository.php';
require_once __DIR__ . '/lib/AssistantDialog.php';
require_once __DIR__ . '/lib/AssistantAccess.php';
require_once __DIR__ . '/lib/AssistantLinks.php';
require_once __DIR__ . '/lib/AssistantTools.php';
require_once __DIR__ . '/lib/AssistantRelations.php';
require_once __DIR__ . '/lib/AssistantUiActions.php';
require_once __DIR__ . '/lib/AssistantHarness.php';
require_once __DIR__ . '/lib/AssistantEval.php';
require_once __DIR__ . '/lib/AssistantEvalCases.php';
require_once __DIR__ . '/lib/AssistantEvalFixtures.php';
require_once __DIR__ . '/lib/AssistantEvalStore.php';
require_once __DIR__ . '/lib/AssistantFeedbackStore.php';
require_once __DIR__ . '/lib/AssistantBotPlugin.php';

Assets::register('assistant', [
    'css' => ['assistant.css'],
    'js' => ['assistant.js', 'eval.js'],
]);

// Assistant chat is base for every signed-in staff user — no module permission gate.
$pages = [
    '/assistant' => [
        'methods' => ['GET'],
        'file' => 'chat_page.php',
    ],
    '/assistant/chat' => [
        'methods' => ['POST'],
        'file' => 'chat.php',
    ],
    '/assistant/confirm' => [
        'methods' => ['POST'],
        'file' => 'confirm.php',
    ],
    '/assistant/clear' => [
        'methods' => ['POST'],
        'file' => 'clear.php',
    ],
    '/assistant/prefs' => [
        'methods' => ['GET', 'POST'],
        'file' => 'prefs.php',
    ],
    '/assistant/fab/hide' => [
        'methods' => ['POST'],
        'file' => 'fab_hide.php',
    ],
    '/assistant/eval' => [
        'methods' => ['GET', 'POST'],
        'file' => 'eval.php',
        'capability' => 'SUPERADMIN_ACCESS',
    ],
    '/assistant/feedback' => [
        'methods' => ['POST'],
        'file' => 'feedback.php',
    ],
];

foreach ($pages as $path => $def) {
    $file = (string)$def['file'];
    $route = [
        'module' => 'assistant',
        'methods' => $def['methods'],
        'handler' => static function () use ($file): void {
            require base_path('modules/assistant/pages/' . $file);
        },
    ];
    if (isset($def['capability'])) {
        $route['capability'] = (string)$def['capability'];
    }
    ModuleManager::registerRoute($path, $route);
}

EventBus::on('bots.ready', static function ($payload) {
    AssistantBotPlugin::register();
    return $payload;
});
AssistantBotPlugin::register();

// Rail chat title = configured persona name (default Макс).
EventBus::on('core.menu.items', static function ($payload) {
    if (!is_array($payload) || !isset($payload['items']) || !is_array($payload['items'])) {
        return $payload;
    }
    $name = AssistantConfig::displayName();
    foreach ($payload['items'] as $i => $item) {
        if (!is_array($item)) {
            continue;
        }
        if (($item['module'] ?? '') === 'assistant' && ($item['path'] ?? '') === '/assistant') {
            $payload['items'][$i]['title'] = $name;
        }
    }
    return $payload;
});

// Persona name on system Settings (/settings) — Agent section slot.
EventBus::on('core.settings.sections', static function ($payload) {
    if (!is_array($payload)) {
        return $payload;
    }
    $payload['agent_html'] = (string)($payload['agent_html'] ?? '') . View::capture('_settings_name', [
        'assistant_name' => AssistantConfig::displayName(),
    ], 'assistant');
    return $payload;
});

EventBus::on('core.settings.save', static function ($payload) {
    if (!is_array($payload)) {
        return $payload;
    }
    $post = is_array($payload['post'] ?? null) ? $payload['post'] : [];
    // HTML always posts the field; JSON may omit to keep current.
    if (array_key_exists('assistant_name', $post)) {
        AssistantConfig::saveFromPost($post);
    }
    return $payload;
});

// Home: replace «Go to…» command HTML (nested emit from core — works even when assistant boots first).
EventBus::on('core.dashboard.command_html', static function ($payload) {
    if (!is_array($payload) || !Auth::check()) {
        return $payload;
    }
    Assets::need('assistant');
    $payload['html'] = View::capture('_widget_ask', [], 'assistant');
    return $payload;
});

// Floating single-letter FAB + page context (personal prefs may hide it).
EventBus::on('core.layout.gate', static function ($payload) {
    if (!is_array($payload) || !Auth::check()) {
        return $payload;
    }
    $uid = Auth::userId();
    $path = request_path();
    $pageTitle = '';
    if (isset($payload['page_title']) && is_string($payload['page_title'])) {
        $pageTitle = $payload['page_title'];
    }
    $ctx = AssistantPageContext::collect($pageTitle);
    $payload['page_context'] = $ctx;
    $payload['page_context_json'] = json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';

    if (!AssistantPrefs::shouldShowFab($uid, $path)) {
        return $payload;
    }

    Assets::need('assistant');
    $history = AssistantDialog::tableReady() ? AssistantDialog::getHistory($uid) : [];
    $openPending = class_exists('AssistantUiActions', false)
        ? AssistantUiActions::pendingPayloadsForUser($uid)
        : [];
    $openUiActions = [];
    if ($openPending !== [] && class_exists('AssistantUiActions', false)) {
        $opsUi = class_exists('AssistantTools', false) ? AssistantTools::availableOps() : [];
        $openUiActions = AssistantUiActions::fromPending($openPending, $opsUi);
    }
    $menuLabels = [];
    foreach (ModuleManager::menuItems() as $item) {
        $p = parse_url((string)($item['path'] ?? ''), PHP_URL_PATH);
        $p = is_string($p) && $p !== '' ? $p : (string)($item['path'] ?? '');
        $p = rtrim($p, '/') ?: '/';
        $title = trim((string)($item['title'] ?? ''));
        if ($p === '' || $title === '') {
            continue;
        }
        if (!isset($menuLabels[$p])) {
            $menuLabels[$p] = $title;
        }
    }
    $an = static function (string $key, array $replace = []): string {
        return AssistantConfig::t($key, $replace);
    };
    $payload['floating'] = (string)($payload['floating'] ?? '') . View::capture('_fab', [
        'page_context' => $ctx,
        'history' => $history,
        'open_pending' => $openPending,
        'open_ui_actions' => $openUiActions,
        'menu_labels' => $menuLabels,
        'admin_base' => AssistantLinks::base(),
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
            'hide_page' => t('assistant.fab.hide_page'),
            'hide_done' => t('assistant.fab.hide_done'),
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
    ], 'assistant');
    return $payload;
});

Health::register('assistant.pending', 'assistant', static function (): array {
    if (!PendingActionsRepository::tableReady()) {
        return [
            'status' => 'fail',
            'message' => 'base_assistant_pending missing — run migrations',
        ];
    }
    return [
        'status' => 'ok',
        'message' => 'Assistant pending table ready',
    ];
}, 'Assistant pending table');

Health::register('assistant.dialog', 'assistant', static function (): array {
    if (!AssistantDialog::tableReady()) {
        return [
            'status' => 'fail',
            'message' => 'base_assistant_dialog missing — run migrations',
        ];
    }
    return [
        'status' => 'ok',
        'message' => 'Assistant dialog table ready',
    ];
}, 'Assistant shared dialog');
