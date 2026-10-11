<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/LlmConfig.php';
require_once __DIR__ . '/lib/LlmClient.php';
require_once __DIR__ . '/lib/OAuthServer.php';
require_once __DIR__ . '/lib/OAuthClientsRepository.php';
require_once __DIR__ . '/lib/OAuthTokensRepository.php';
require_once __DIR__ . '/lib/BearerAuth.php';
require_once __DIR__ . '/lib/McpServer.php';
require_once __DIR__ . '/lib/OpenApiBuilder.php';
require_once __DIR__ . '/lib/LlmAgentOps.php';

LlmAgentOps::register();

$llmHome = ModuleManager::isEnabled('assistant') ? '/assistant' : '/llm/tokens';
ModuleManager::registerMenuGroup('llm.menu.group', 65, $llmHome);

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
    '/llm/oauth' => [
        'permission' => 'llm.manage',
        'capability' => 'SUPERADMIN_ACCESS',
        'methods' => ['GET', 'POST'],
        'file' => 'oauth_clients.php',
    ],
    '/llm/oauth/new' => [
        'permission' => 'llm.manage',
        'capability' => 'SUPERADMIN_ACCESS',
        'methods' => ['GET', 'POST'],
        'file' => 'oauth_client_edit.php',
    ],
    '/llm/oauth/{id}' => [
        'permission' => 'llm.manage',
        'capability' => 'SUPERADMIN_ACCESS',
        'methods' => ['GET', 'POST'],
        'file' => 'oauth_client_edit.php',
    ],
    '/llm/openapi.json' => [
        'public' => true,
        'methods' => ['GET', 'OPTIONS'],
        'file' => 'openapi.php',
    ],
    '/llm/mcp' => [
        'public' => true,
        'methods' => ['GET', 'POST', 'DELETE', 'OPTIONS'],
        'file' => 'mcp.php',
    ],
    '/llm/api/tools' => [
        'public' => true,
        'methods' => ['GET', 'OPTIONS'],
        'file' => 'api_tools.php',
    ],
    '/llm/api/tools/call' => [
        'public' => true,
        'methods' => ['POST', 'OPTIONS'],
        'file' => 'api_tools.php',
    ],
];

foreach ($pages as $path => $def) {
    $file = (string)$def['file'];
    $route = [
        'module' => 'llm',
        'methods' => $def['methods'],
        'handler' => static function () use ($file): void {
            require base_path('modules/llm/pages/' . $file);
        },
    ];
    if (!empty($def['public'])) {
        $route['public'] = true;
    }
    if (!empty($def['permission'])) {
        $route['permission'] = $def['permission'];
    }
    if (!empty($def['capability'])) {
        $route['capability'] = $def['capability'];
    }
    ModuleManager::registerRoute($path, $route);
}

ModuleManager::registerRoute('/oauth/authorize', [
    'module' => 'llm',
    'methods' => ['GET', 'POST'],
    'public' => true,
    'handler' => static function (): void {
        require base_path('modules/llm/pages/oauth_authorize.php');
    },
]);

ModuleManager::registerRoute('/oauth/token', [
    'module' => 'llm',
    'methods' => ['POST', 'OPTIONS'],
    'public' => true,
    'handler' => static function (): void {
        require base_path('modules/llm/pages/oauth_token.php');
    },
]);

ModuleManager::registerRoute('/oauth/revoke', [
    'module' => 'llm',
    'methods' => ['POST', 'OPTIONS'],
    'public' => true,
    'handler' => static function (): void {
        require base_path('modules/llm/pages/oauth_revoke.php');
    },
]);

ModuleManager::registerRoute('/oauth/register', [
    'module' => 'llm',
    'methods' => ['POST', 'OPTIONS'],
    'public' => true,
    'handler' => static function (): void {
        require base_path('modules/llm/pages/oauth_register.php');
    },
]);

$oauthMeta = [
    '/.well-known/oauth-authorization-server',
    '/.well-known/openid-configuration',
    '/.well-known/oauth-protected-resource',
    '/.well-known/oauth-protected-resource/llm/mcp',
];
foreach ($oauthMeta as $metaPath) {
    ModuleManager::registerRoute($metaPath, [
        'module' => 'llm',
        'methods' => ['GET', 'OPTIONS'],
        'public' => true,
        'handler' => static function (): void {
            require base_path('modules/llm/pages/oauth_metadata.php');
        },
    ]);
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
        'status' => 'skipped',
        'error_code' => 'llm_profile_unconfigured',
        'message' => t('llm.health.missing'),
        'meta' => ['hint' => '/llm/tokens'],
    ];
}, 'LLM default profile');

Health::register('llm.oauth', 'llm', static function (): array {
    if (!OAuthClientsRepository::tableReady()) {
        return [
            'status' => 'fail',
            'message' => 'OAuth tables missing — run migrate',
        ];
    }
    $n = count(OAuthClientsRepository::listAll());
    return [
        'status' => 'ok',
        'message' => t('llm.oauth.health', ['n' => $n]),
        'meta' => [
            'clients' => $n,
            'authorize' => OAuthServer::authorizeUrl(),
            'mcp' => OAuthServer::mcpUrl(),
        ],
    ];
}, 'OAuth / MCP adapter');
