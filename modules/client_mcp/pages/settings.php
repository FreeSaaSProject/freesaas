<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$message = '';
$error = '';
$tablesReady = ClientMcpHostsRepository::tableReady() && ClientMcpSessionsRepository::tableReady();

/**
 * @param array<string,mixed> $host
 * @return list<array{name:string,title:string,module:string,core:bool,enabled:bool,reason:string}>
 */
$toolRows = static function (array $host): array {
    $rows = [];
    foreach (ClientMcpTools::registry() as $tool) {
        $reason = ClientMcpTools::unavailableReason($tool, $host);
        $rows[] = [
            'name' => (string)$tool['name'],
            'title' => (string)$tool['title'],
            'module' => (string)$tool['module'],
            'core' => !empty($tool['core']),
            'enabled' => $reason !== 'disabled',
            'reason' => $reason === 'disabled' ? '' : $reason,
        ];
    }
    return $rows;
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        if (!$tablesReady) {
            throw new RuntimeException(t('client_mcp.health.table_missing'));
        }
        ClientMcpConfig::saveFromPost($_POST);
        $postedHosts = is_array($_POST['hosts'] ?? null) ? $_POST['hosts'] : [];
        $registry = ClientMcpTools::registry();
        foreach (ClientMcpHost::all() as $h) {
            $in = $postedHosts[(string)$h['id']] ?? null;
            if (!is_array($in) || empty($in['present'])) {
                continue;
            }
            $consent = trim((string)($in['consent_url'] ?? ''));
            if ($consent !== '' && !preg_match('#^(https?://|/)\S+$#i', $consent)) {
                throw new InvalidArgumentException(t('client_mcp.err.consent_url') . ' (' . $h['host'] . ')');
            }
            $on = array_map('strval', is_array($in['tools'] ?? null) ? $in['tools'] : []);
            $disabled = [];
            foreach ($registry as $name => $tool) {
                if (empty($tool['core']) && !in_array($name, $on, true)) {
                    $disabled[] = $name;
                }
            }
            ClientMcpHostsRepository::save($h['id'], [
                'enabled' => !empty($in['enabled']),
                'server_name' => trim(preg_replace('/\s+/u', ' ', (string)($in['server_name'] ?? '')) ?? ''),
                'description' => trim(preg_replace('/\s+/u', ' ', (string)($in['description'] ?? '')) ?? ''),
                'consent_url' => $consent,
                'show_note' => !empty($in['show_note']),
                'note_text' => trim(preg_replace('/\s+/u', ' ', strip_tags((string)($in['note_text'] ?? ''))) ?? ''),
                'disabled_tools' => $disabled,
            ]);
        }
        $message = t('core.common.saved');
    } catch (Throwable $e) {
        if ($wantsJson) {
            AgentHttp::error($e->getMessage(), 400);
        }
        $error = $e->getMessage();
    }
}

$hosts = [];
foreach (ClientMcpHost::all() as $h) {
    $serverKey = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($h['host'])) ?? 'booking', '-');
    $hosts[] = [
        'id' => $h['id'],
        'host' => $h['host'],
        'name' => $h['name'],
        'site_enabled' => !empty($h['site_enabled']),
        'settings' => $h['settings'],
        'endpoints' => [
            'mcp' => ClientMcpHost::mcpUrl($h),
            'server_card' => ClientMcpHost::serverCardUrl($h),
            'ai_catalog' => ClientMcpHost::aiCatalogUrl($h),
        ],
        'tools' => $toolRows($h),
        'stats' => ClientMcpSessionsRepository::stats($h['id']),
        'snippet' => (string)json_encode(
            ['mcpServers' => [$serverKey => ['url' => ClientMcpHost::mcpUrl($h)]]],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ),
    ];
}

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'saved' => $message !== '',
        'tables_ready' => $tablesReady,
        'settings' => ClientMcpConfig::formValues(),
        'hosts' => array_map(static function (array $h): array {
            unset($h['snippet']);
            return $h;
        }, $hosts),
    ]);
}

View::render('settings', [
    'title' => t('client_mcp.settings.title'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('client_mcp.settings.title'), 'current' => true],
    ]),
    'message' => $message,
    'error' => $error,
    'tablesReady' => $tablesReady,
    'cfg' => ClientMcpConfig::formValues(),
    'hosts' => $hosts,
], 'layout', 'client_mcp');
