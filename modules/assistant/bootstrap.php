<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/PendingActionsRepository.php';
require_once __DIR__ . '/lib/AssistantTools.php';
require_once __DIR__ . '/lib/AssistantHarness.php';
require_once __DIR__ . '/lib/AssistantBotPlugin.php';

Assets::register('assistant', [
    'css' => ['assistant.css'],
    'js' => ['assistant.js'],
]);

$pages = [
    '/assistant' => [
        'permission' => 'assistant.use',
        'methods' => ['GET'],
        'file' => 'chat_page.php',
    ],
    '/assistant/chat' => [
        'permission' => 'assistant.use',
        'methods' => ['POST'],
        'file' => 'chat.php',
    ],
    '/assistant/confirm' => [
        'permission' => 'assistant.use',
        'methods' => ['POST'],
        'file' => 'confirm.php',
    ],
];

foreach ($pages as $path => $def) {
    $file = (string)$def['file'];
    ModuleManager::registerRoute($path, [
        'module' => 'assistant',
        'methods' => $def['methods'],
        'permission' => $def['permission'],
        'handler' => static function () use ($file): void {
            require base_path('modules/assistant/pages/' . $file);
        },
    ]);
}

EventBus::on('bots.ready', static function ($payload) {
    AssistantBotPlugin::register();
    return $payload;
});
AssistantBotPlugin::register();

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
