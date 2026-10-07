<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/LlmConfig.php';
require_once __DIR__ . '/lib/LlmClient.php';

$pages = [
    '/llm/tokens' => [
        'permission' => 'llm.manage',
        'capability' => 'SUPERADMIN_ACCESS',
        'methods' => ['GET', 'POST'],
        'file' => 'tokens_list.php',
    ],
    '/llm/tokens/new' => [
        'permission' => 'llm.manage',
        'capability' => 'SUPERADMIN_ACCESS',
        'methods' => ['GET', 'POST'],
        'file' => 'tokens_edit.php',
    ],
    '/llm/tokens/{id}' => [
        'permission' => 'llm.manage',
        'capability' => 'SUPERADMIN_ACCESS',
        'methods' => ['GET', 'POST'],
        'file' => 'tokens_edit.php',
    ],
    '/llm/chat' => [
        'permission' => 'llm.use',
        'methods' => ['POST'],
        'file' => 'chat.php',
    ],
];

foreach ($pages as $path => $def) {
    $file = (string)$def['file'];
    $route = [
        'module' => 'llm',
        'methods' => $def['methods'],
        'permission' => $def['permission'],
        'handler' => static function () use ($file): void {
            require base_path('modules/llm/pages/' . $file);
        },
    ];
    if (!empty($def['capability'])) {
        $route['capability'] = $def['capability'];
    }
    ModuleManager::registerRoute($path, $route);
}

Health::register('llm.profile', 'llm', static function (): array {
    if (LlmConfig::hasReadyProfile()) {
        $cfg = LlmConfig::resolve(null);
        return [
            'status' => 'ok',
            'message' => t('llm.health.ok'),
            'meta' => ['profile' => $cfg['id'] ?? '', 'model' => $cfg['model_chat'] ?? ''],
        ];
    }
    return [
        'status' => 'degraded',
        'message' => t('llm.health.missing'),
        'meta' => ['hint' => '/llm/tokens'],
    ];
}, 'LLM default profile');
