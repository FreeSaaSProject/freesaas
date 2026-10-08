<?php
declare(strict_types=1);

require_once base_path('modules/core/lib/ModulesAdmin.php');

$wantsJson = AgentHttp::wantsJson();

$id = trim((string)Router::param('id'));
$card = $id !== '' ? ModuleCatalog::detail($id) : null;

if ($card === null) {
    if ($wantsJson) {
        AgentHttp::error(t('core.modules.err.no_module'), 404);
    }
    http_response_code(404);
    echo t('core.modules.err.no_module');
    return;
}

$detailPath = '/modules/' . rawurlencode($id);

if (!$wantsJson) {
    $handled = ModulesAdmin::handlePost($detailPath);
    if ($handled !== null) {
        redirect($handled['redirect']);
    }
}

if ($wantsJson) {
    $r = AgentOps::moduleInspect($id);
    unset($r['http']);
    $ops = [];
    foreach (AgentHttp::opsForModule($id) as $op) {
        $mcpName = str_replace('.', '__', (string)$op['id']);
        $mcpName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $mcpName) ?? $mcpName;
        if (strlen($mcpName) > 64) {
            $mcpName = substr($mcpName, 0, 64);
        }
        $ops[] = [
            'id' => $op['id'],
            'mcp_name' => $mcpName,
            'method' => $op['method'],
            'path' => $op['path'],
            'permission' => $op['permission'],
            'description' => $op['description'],
            'side_effect' => $op['side_effect'],
            'assistant' => !empty($op['assistant']),
            'in_mcp' => !empty($card['enabled']) && !empty($op['assistant']),
        ];
    }
    if (isset($r['module']) && is_array($r['module'])) {
        $r['module']['agent_ops'] = $ops;
        $r['module']['agent_ops_note'] = 'MCP tools/list publishes agent_ops with assistant=true from enabled modules only. mcp_name is the tools/call name.';
    }
    AgentHttp::emit($r);
    return;
}

$message = trim((string)($_GET['msg'] ?? ''));
$error = trim((string)($_GET['err'] ?? ''));
$displayName = ModuleCatalog::display($card['name'], $id);

View::render('modules_detail', [
    'title' => $displayName,
    'card' => $card,
    'detailPath' => $detailPath,
    'message' => $message,
    'error' => $error,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('core.menu.modules'), 'href' => '/modules'],
        ['label' => $displayName, 'current' => true],
    ]),
], 'layout', 'core');
