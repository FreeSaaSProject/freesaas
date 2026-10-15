<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/AssistantConfig.php';
require_once __DIR__ . '/lib/PendingActionsRepository.php';
require_once __DIR__ . '/lib/AssistantDialog.php';
require_once __DIR__ . '/lib/AssistantAccess.php';
require_once __DIR__ . '/lib/AssistantLinks.php';
require_once __DIR__ . '/lib/AssistantTools.php';
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
