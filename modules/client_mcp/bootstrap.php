<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/ClientMcpConfig.php';
require_once __DIR__ . '/lib/ClientMcpHostsRepository.php';
require_once __DIR__ . '/lib/ClientMcpHost.php';
require_once __DIR__ . '/lib/ClientMcpSessionsRepository.php';
require_once __DIR__ . '/lib/ClientMcpToolError.php';
require_once __DIR__ . '/lib/ClientMcpContext.php';
require_once __DIR__ . '/lib/ClientMcpTransport.php';
require_once __DIR__ . '/lib/ClientMcpTools.php';
require_once __DIR__ . '/lib/ClientMcpCoreTools.php';
require_once __DIR__ . '/lib/ClientMcpBookingTools.php';
require_once __DIR__ . '/lib/ClientMcpServer.php';
require_once __DIR__ . '/lib/ClientMcpDiscovery.php';

$publicRoutes = [
    '/mcp' => [
        'methods' => ['GET', 'POST', 'DELETE', 'OPTIONS'],
        'handler' => static function (): void {
            ClientMcpServer::handle();
        },
    ],
    '/mcp/server-card' => [
        'methods' => ['GET', 'OPTIONS'],
        'handler' => static function (): void {
            ClientMcpDiscovery::emitServerCard();
        },
    ],
];
foreach ($publicRoutes as $path => $def) {
    ModuleManager::registerRoute($path, [
        'module' => 'client_mcp',
        'methods' => $def['methods'],
        'public' => true,
        // Cookie-less JSON-RPC from remote agents; no session state is trusted.
        'csrf' => false,
        'handler' => $def['handler'],
    ]);
}

ModuleManager::registerRoute('/client-mcp/settings', [
    'module' => 'client_mcp',
    'methods' => ['GET', 'POST'],
    'capability' => 'SUPERADMIN_ACCESS',
    'handler' => static function (): void {
        require base_path('modules/client_mcp/pages/settings.php');
    },
]);

EventBus::on('client_mcp.tools', [ClientMcpCoreTools::class, 'onTools']);
EventBus::on('client_mcp.tools', [ClientMcpBookingTools::class, 'onTools']);
EventBus::on('client_mcp.business_info', [ClientMcpBookingTools::class, 'onBusinessInfo']);
EventBus::on('sites.ai_catalog', [ClientMcpDiscovery::class, 'onAiCatalog']);
EventBus::on('orders.sources', static function ($payload) {
    if (is_array($payload)) {
        $payload['sources'][] = 'mcp';
    }
    return $payload;
});

Cron::register('client_mcp.purge', [
    'module' => 'client_mcp',
    'label' => 'client_mcp.cron.purge',
    'daily_at' => '04:10',
    'handler' => static function (): array {
        $n = ClientMcpSessionsRepository::purgeExpired();
        return ['ok' => true, 'processed' => $n];
    },
]);

Health::register('client_mcp.endpoint', 'client_mcp', static function (): array {
    if (!ClientMcpHostsRepository::tableReady() || ClientMcpHostsRepository::countEnabled() < 1) {
        // Off (or never migrated) is a valid state for an optional public channel.
        return [
            'status' => 'skipped',
            'error_code' => 'client_mcp_disabled',
            'message' => t('client_mcp.health.disabled'),
            'meta' => ['hint' => '/client-mcp/settings'],
        ];
    }
    if (!ClientMcpSessionsRepository::tableReady()
        || !Database::columnExists('base_client_mcp_sessions', 'SITE_HOST_ID')
    ) {
        return [
            'status' => 'fail',
            'error_code' => 'client_mcp_table_missing',
            'message' => t('client_mcp.health.table_missing'),
        ];
    }
    $hosts = [];
    $broken = [];
    foreach (ClientMcpHost::all() as $h) {
        if (empty($h['settings']['enabled'])) {
            continue;
        }
        $n = ClientMcpTools::countProductTools($h);
        $hosts[$h['host']] = ['mcp' => ClientMcpHost::mcpUrl($h), 'tools' => $n];
        if ($n < 1) {
            $broken[] = $h['host'];
        }
    }
    if ($broken !== []) {
        return [
            'status' => 'fail',
            'error_code' => 'client_mcp_no_tools',
            'message' => t('client_mcp.health.no_tools', ['hosts' => implode(', ', $broken)]),
            'meta' => ['hosts' => $hosts],
        ];
    }
    return [
        'status' => 'ok',
        'message' => t('client_mcp.health.ok', ['hosts' => implode(', ', array_keys($hosts))]),
        'meta' => ['hosts' => $hosts],
    ];
}, 'Public client MCP');
