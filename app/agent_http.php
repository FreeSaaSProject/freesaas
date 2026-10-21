<?php
declare(strict_types=1);

/**
 * HTTP Agent API front controller (token auth). Same ops as bin/freesaas.php.
 * Shared by core /agent/* routes and module repair routes (/agent/bots|gcal|llm/…).
 */
$path = request_path();
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
// Break-glass: valid var/setup.key unlocks rescue / bootstrap (never a free virgin door).
$allowSetupKey = ($method === 'POST' && ($path === '/agent/rescue' || $path === '/agent/bootstrap'));

// JSON body may carry setup_key — promote before the gate (php://input is cached).
if ($allowSetupKey && SetupKey::providedKey() === '') {
    $peek = agent_api_json_body();
    if (!empty($peek['setup_key']) && is_string($peek['setup_key'])) {
        $_POST['setup_key'] = $peek['setup_key'];
    }
}

// Binary dump download (not JSON) — header/Bearer or short-lived ?exp=&sig= (never raw ?token=).
if ($path === '/agent/db/dump/file' && $method === 'GET') {
    AgentAuth::assertDumpFileAccess();
} else {
    AgentAuth::assertToken($allowSetupKey);
}

if ($path === '/agent/db/dump/file' && $method === 'GET') {
    try {
        $file = (string)($_GET['file'] ?? '');
        $resolved = DbTools::resolveDumpPath($file);
        $name = basename($resolved);
        $size = (int)filesize($resolved);
        $start = 0;
        $end = max(0, $size - 1);
        $range = (string)($_SERVER['HTTP_RANGE'] ?? '');
        if ($range !== '' && preg_match('/bytes=(\d+)-(\d*)/', $range, $m)) {
            $start = (int)$m[1];
            if ($m[2] !== '') {
                $end = min($end, (int)$m[2]);
            }
            if ($start > $end || $start >= $size) {
                http_response_code(416);
                header('Content-Range: bytes */' . $size);
                return;
            }
            http_response_code(206);
            header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
        }
        $len = $end - $start + 1;
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . str_replace(['"', "\r", "\n"], '', $name) . '"');
        header('Accept-Ranges: bytes');
        header('Content-Length: ' . (string)$len);
        header('Cache-Control: no-store');
        $fh = fopen($resolved, 'rb');
        if ($fh === false) {
            throw new RuntimeException('Cannot read dump file');
        }
        if ($start > 0) {
            fseek($fh, $start);
        }
        $left = $len;
        while ($left > 0 && !feof($fh)) {
            $chunk = fread($fh, min(1024 * 1024, $left));
            if ($chunk === false || $chunk === '') {
                break;
            }
            echo $chunk;
            $left -= strlen($chunk);
        }
        fclose($fh);
    } catch (Throwable $e) {
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    return;
}

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
    if ($path === '/agent/rescue' && $method === 'POST') {
        $opts = agent_api_json_body();
        foreach (['login', 'password', 'name', 'mail', 'setup_key'] as $k) {
            if (!isset($opts[$k]) && isset($_POST[$k])) {
                $opts[$k] = $_POST[$k];
            }
        }
        if (isset($opts['setup_key']) && is_string($opts['setup_key']) && $opts['setup_key'] !== '') {
            $_POST['setup_key'] = (string)$opts['setup_key'];
        }
        if (!empty($opts['clear_setup_key']) || !empty($_POST['clear_setup_key'])) {
            $opts['clear_setup_key'] = true;
        }
        return AgentOps::rescue($opts);
    }
    if ($path === '/agent/system/status' && $method === 'GET') {
        return AgentOps::systemStatus();
    }
    if ($path === '/agent/modules' && $method === 'GET') {
        return AgentOps::moduleList([
            'q' => (string)($_GET['q'] ?? ''),
            'category' => (string)($_GET['category'] ?? ''),
            'tag' => (string)($_GET['tag'] ?? ''),
            'status' => (string)($_GET['status'] ?? ''),
            'maturity' => (string)($_GET['maturity'] ?? ''),
        ]);
    }
    if ($path === '/agent/validate' && $method === 'GET') {
        return AgentOps::moduleValidate(null);
    }
    if ($path === '/agent/db' && $method === 'GET') {
        return AgentOps::dbStatus();
    }
    if ($path === '/agent/db/migrate' && $method === 'GET') {
        $mod = isset($_GET['module']) ? (string)$_GET['module'] : null;
        return AgentOps::migrateStatus($mod);
    }
    if ($path === '/agent/db/migrate/run' && $method === 'POST') {
        $mod = isset($_GET['module']) ? (string)$_GET['module'] : (isset($_POST['module']) ? (string)$_POST['module'] : null);
        return AgentOps::migrateRun($mod);
    }
    if ($path === '/agent/db/data-migrate' && $method === 'GET') {
        $mod = isset($_GET['module']) ? (string)$_GET['module'] : null;
        return AgentOps::dataMigrateList($mod);
    }
    if ($path === '/agent/db/data-migrate/run' && $method === 'POST') {
        $mod = isset($_GET['module']) ? (string)$_GET['module'] : (isset($_POST['module']) ? (string)$_POST['module'] : null);
        $id = isset($_GET['id']) ? (string)$_GET['id'] : (isset($_POST['id']) ? (string)$_POST['id'] : null);
        return AgentOps::dataMigrateRun($mod, $id);
    }
    if ($path === '/agent/db/dumps' && $method === 'GET') {
        return AgentOps::dbListDumps();
    }
    if ($path === '/agent/db/dump' && $method === 'POST') {
        $label = isset($_GET['label']) ? (string)$_GET['label'] : (isset($_POST['label']) ? (string)$_POST['label'] : null);
        return AgentOps::dbDump($label);
    }
    if ($path === '/agent/db/restore' && $method === 'POST') {
        $file = isset($_GET['file']) ? (string)$_GET['file'] : (isset($_POST['file']) ? (string)$_POST['file'] : '');
        return AgentOps::dbRestore($file);
    }
    if ($path === '/agent/db/dump/delete' && $method === 'POST') {
        $file = isset($_GET['file']) ? (string)$_GET['file'] : (isset($_POST['file']) ? (string)$_POST['file'] : '');
        return AgentOps::dbDeleteDump($file);
    }
    if ($path === '/agent/db/check' && $method === 'POST') {
        return AgentOps::dbCheck();
    }
    if ($path === '/agent/db/repair' && $method === 'POST') {
        return AgentOps::dbRepair();
    }
    if ($path === '/agent/db/optimize' && $method === 'POST') {
        return AgentOps::dbOptimize();
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
    if ($path === '/agent/doctor' && $method === 'GET') {
        return AgentOps::doctor();
    }
    if ($path === '/agent/i18n' && $method === 'GET') {
        $loc = isset($_GET['locale']) ? (string)$_GET['locale'] : null;
        return AgentOps::localeValidate($loc);
    }
    if ($path === '/agent/update' && $method === 'GET') {
        return AgentOps::updateStatus();
    }
    if ($path === '/agent/update/check' && $method === 'GET') {
        $target = isset($_GET['target']) ? (string)$_GET['target'] : null;
        return AgentOps::updateCheck($target !== '' ? $target : null);
    }
    if ($path === '/agent/update/apply' && $method === 'POST') {
        $body = agent_api_json_body();
        $target = null;
        if (isset($body['target'])) {
            $target = (string)$body['target'];
        } elseif (isset($_GET['target'])) {
            $target = (string)$_GET['target'];
        } elseif (isset($_POST['target'])) {
            $target = (string)$_POST['target'];
        }
        if ($target === '') {
            $target = null;
        }
        $migrate = true;
        if (array_key_exists('migrate', $body)) {
            $migrate = !empty($body['migrate']);
        } elseif (isset($_GET['migrate'])) {
            $migrate = !in_array(strtolower((string)$_GET['migrate']), ['0', 'false', 'no'], true);
        } elseif (isset($_POST['migrate'])) {
            $migrate = !in_array(strtolower((string)$_POST['migrate']), ['0', 'false', 'no'], true);
        }
        return AgentOps::updateApply($target, $migrate);
    }

    if ($id !== '' && strpos($path, '/agent/modules/') === 0) {
        if ($path === '/agent/modules/' . $id && $method === 'GET') {
            return AgentOps::moduleInspect($id);
        }
        if ($path === '/agent/modules/' . $id . '/enable' && $method === 'POST') {
            $body = agent_api_json_body();
            $accept = !empty($_GET['accept_risk'])
                || !empty($_POST['accept_risk'])
                || !empty($body['accept_risk']);
            return AgentOps::moduleSetEnabled($id, true, (bool)$accept);
        }
        if ($path === '/agent/modules/' . $id . '/install' && $method === 'POST') {
            return AgentOps::moduleInstall($id);
        }
        if ($path === '/agent/modules/' . $id . '/uninstall' && $method === 'POST') {
            $body = agent_api_json_body();
            $confirm = !empty($_GET['confirm_uninstall'])
                || !empty($_POST['confirm_uninstall'])
                || !empty($body['confirm_uninstall']);
            return AgentOps::moduleUninstall($id, (bool)$confirm);
        }
        if ($path === '/agent/modules/' . $id . '/disable' && $method === 'POST') {
            return AgentOps::moduleSetEnabled($id, false);
        }
        if ($path === '/agent/modules/' . $id . '/validate' && $method === 'GET') {
            return AgentOps::moduleValidate($id);
        }
    }

    $module = AgentOps::dispatchModuleOp($path, $method);
    if ($module !== null) {
        return $module;
    }

    return ['ok' => false, 'error' => 'unknown agent endpoint', 'hint' => 'GET /agent for catalog', 'http' => 404];
}

/** @return array<string,mixed> */
function agent_api_json_body(): array
{
    static $cached = null;
    if (is_array($cached)) {
        return $cached;
    }
    $raw = request_raw_body();
    if (trim($raw) === '') {
        $cached = [];
        return $cached;
    }
    $data = json_decode($raw, true);
    $cached = is_array($data) ? $data : [];
    return $cached;
}
