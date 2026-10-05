<?php
declare(strict_types=1);

/**
 * HTTP Agent API front controller (token auth). Same ops as bin/freesaas.php.
 */
$path = request_path();
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
// Virgin install: catalog + bootstrap without prior token (bootstrap persists agent_token).
$allowVirgin = ($method === 'POST' && $path === '/agent/bootstrap')
    || ($method === 'GET' && ($path === '/agent' || $path === '/agent/'));

AgentAuth::assertToken($allowVirgin);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$id = (string)(Router::param('id') ?? '');

try {
    $result = agent_api_dispatch($path, $method, $id);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return;
}

$http = (int)($result['http'] ?? 200);
unset($result['http']);
http_response_code($http);
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
return;

/**
 * @return array<string, mixed>
 */
function agent_api_dispatch(string $path, string $method, string $id): array
{
    if ($path === '/agent' || $path === '/agent/') {
        return AgentOps::catalog();
    }
    if ($path === '/agent/bootstrap' && $method === 'POST') {
        $opts = agent_api_json_body();
        // Also accept form fields
        foreach (['login', 'password', 'name', 'mail', 'locale', 'agent_token'] as $k) {
            if (!isset($opts[$k]) && isset($_POST[$k])) {
                $opts[$k] = $_POST[$k];
            }
        }
        if (isset($_POST['modules']) && is_string($_POST['modules'])) {
            $opts['modules'] = array_filter(array_map('trim', explode(',', $_POST['modules'])));
        }
        return AgentOps::bootstrap($opts);
    }
    if ($path === '/agent/system/status' && $method === 'GET') {
        return AgentOps::systemStatus();
    }
    if ($path === '/agent/modules' && $method === 'GET') {
        return AgentOps::moduleList();
    }
    if ($path === '/agent/validate' && $method === 'GET') {
        return AgentOps::moduleValidate(null);
    }
    if ($path === '/agent/migrate' && $method === 'GET') {
        $mod = isset($_GET['module']) ? (string)$_GET['module'] : null;
        return AgentOps::migrateStatus($mod);
    }
    if ($path === '/agent/migrate/run' && $method === 'POST') {
        $mod = isset($_GET['module']) ? (string)$_GET['module'] : (isset($_POST['module']) ? (string)$_POST['module'] : null);
        return AgentOps::migrateRun($mod);
    }
    if ($path === '/agent/locale' && $method === 'GET') {
        return AgentOps::localeList();
    }
    if ($path === '/agent/locale/validate' && $method === 'GET') {
        $loc = isset($_GET['locale']) ? (string)$_GET['locale'] : null;
        return AgentOps::localeValidate($loc);
    }
    if ($path === '/agent/health' && $method === 'GET') {
        $mod = isset($_GET['module']) ? (string)$_GET['module'] : null;
        return AgentOps::health($mod);
    }
    if ($path === '/agent/i18n' && $method === 'GET') {
        $loc = isset($_GET['locale']) ? (string)$_GET['locale'] : null;
        return AgentOps::localeValidate($loc);
    }

    if ($id !== '' && strpos($path, '/agent/modules/') === 0) {
        if ($path === '/agent/modules/' . $id && $method === 'GET') {
            return AgentOps::moduleInspect($id);
        }
        if ($path === '/agent/modules/' . $id . '/enable' && $method === 'POST') {
            return AgentOps::moduleSetEnabled($id, true);
        }
        if ($path === '/agent/modules/' . $id . '/disable' && $method === 'POST') {
            return AgentOps::moduleSetEnabled($id, false);
        }
        if ($path === '/agent/modules/' . $id . '/validate' && $method === 'GET') {
            return AgentOps::moduleValidate($id);
        }
    }

    return ['ok' => false, 'error' => 'unknown agent endpoint', 'hint' => 'GET /agent for catalog', 'http' => 404];
}

/** @return array<string,mixed> */
function agent_api_json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}
