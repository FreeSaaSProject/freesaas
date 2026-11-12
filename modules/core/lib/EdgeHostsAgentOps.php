<?php
declare(strict_types=1);

/**
 * Surface A (/agent/edge-hosts) — register and drive edge workers hosts with agent_token.
 */
final class EdgeHostsAgentOps
{
    public static function register(): void
    {
        AgentOps::registerModuleOp('GET', '/agent/edge-hosts', 'core.edge_hosts.list', [self::class, 'list'], [
            'description' => 'Edge workers hosts: address, key, pin set, services (asr/video), last status per service, errors (no secrets)',
        ]);
        AgentOps::registerModuleOp('POST', '/agent/edge-hosts', 'core.edge_hosts.manage', [self::class, 'manage'], [
            'description' => 'Manage edge host (confirm=1): action=create|update|service|test|rotate|promote|delete; id, name, base_url, key_id, pin, enabled, service, service_enabled, max_inflight. Secret returned once on create/rotate',
            'body' => ['confirm', 'action', 'id', 'name', 'base_url', 'key_id', 'pin', 'enabled', 'service', 'service_enabled', 'max_inflight'],
        ]);
        ModuleManager::registerRoute('/agent/edge-hosts', [
            'module' => 'core',
            'public' => true,
            'csrf' => false,
            'methods' => ['GET', 'POST'],
            'handler' => static function (): void {
                require_once base_path('app/agent_http.php');
            },
        ]);
    }

    /** @return array<string,mixed> */
    public static function list(): array
    {
        if (!EdgeHosts::available()) {
            return ['ok' => false, 'error' => 'base_edge_hosts missing — run migrate', 'http' => 409];
        }
        $hosts = [];
        foreach (EdgeHosts::all() as $h) {
            $services = [];
            foreach (EdgeHosts::plugins($h) as $svc => $cfg) {
                $services[$svc] = $cfg + ['last_status' => EdgeHosts::lastStatus($h, $svc)];
            }
            $hosts[] = [
                'id' => (int)$h['ID'],
                'name' => (string)$h['NAME'],
                'transport' => (string)$h['TRANSPORT'],
                'base_url' => (string)$h['BASE_URL'],
                'key_id' => (string)$h['KEY_ID'],
                'enabled' => (int)$h['ENABLED'] === 1,
                'pin_set' => EdgeHostClient::validPin((string)$h['PIN_SHA256']),
                'secret_next_pending' => (string)$h['SECRET_NEXT'] !== '',
                'api_version' => (int)$h['API_VERSION'],
                'last_seen' => (int)$h['LAST_SEEN'],
                'last_error' => (string)$h['LAST_ERROR'],
                'backoff_until' => (int)$h['BACKOFF_UNTIL'],
                'services' => $services,
            ];
        }
        return ['ok' => true, 'known_services' => array_keys(EdgeHosts::services()), 'hosts' => $hosts];
    }

    /** @return array<string,mixed> */
    public static function manage(): array
    {
        $in = AgentOps::requestInput();
        $c = $in['confirm'] ?? null;
        if (!($c === true || $c === 1 || $c === '1')) {
            return ['ok' => false, 'error' => 'confirm=1 required', 'http' => 400];
        }
        if (!EdgeHosts::available()) {
            return ['ok' => false, 'error' => 'base_edge_hosts missing — run migrate', 'http' => 409];
        }
        $action = (string)($in['action'] ?? '');
        if ($action === 'create') {
            $name = trim((string)($in['name'] ?? ''));
            $url = trim((string)($in['base_url'] ?? ''));
            $pin = trim((string)($in['pin'] ?? ''));
            if ($name === '' || !EdgeHostClient::validBaseUrl($url)) {
                return ['ok' => false, 'error' => 'name and base_url https://host:port required', 'http' => 400];
            }
            if ($pin !== '' && !EdgeHostClient::validPin($pin)) {
                return ['ok' => false, 'error' => 'pin must be base64 sha256 (44 chars)', 'http' => 400];
            }
            $r = EdgeHosts::create($name, $url, $pin, [], (string)($in['key_id'] ?? 'owner'));
            return ['ok' => true, 'id' => $r['id'], 'secret' => $r['secret'], 'note' => 'secret shown once → host gateway.clients[].secret; host created disabled'];
        }

        $id = (int)($in['id'] ?? 0);
        $h = EdgeHosts::find($id);
        if ($h === null) {
            return ['ok' => false, 'error' => 'host not found', 'http' => 404];
        }
        switch ($action) {
            case 'update':
                $url = array_key_exists('base_url', $in) ? trim((string)$in['base_url']) : (string)$h['BASE_URL'];
                $pin = array_key_exists('pin', $in) ? trim((string)$in['pin']) : (string)$h['PIN_SHA256'];
                $enabled = array_key_exists('enabled', $in) ? self::truthy($in['enabled']) : (int)$h['ENABLED'] === 1;
                if ($url !== '' && !EdgeHostClient::validBaseUrl($url)) {
                    return ['ok' => false, 'error' => 'bad base_url', 'http' => 400];
                }
                if ($pin !== '' && !EdgeHostClient::validPin($pin)) {
                    return ['ok' => false, 'error' => 'bad pin', 'http' => 400];
                }
                if ($enabled && ($url === '' || $pin === '')) {
                    return ['ok' => false, 'error' => 'enable requires base_url and pin', 'http' => 400];
                }
                EdgeHosts::update(
                    $id,
                    array_key_exists('name', $in) ? (string)$in['name'] : (string)$h['NAME'],
                    $url,
                    $pin,
                    $enabled,
                    array_key_exists('key_id', $in) ? (string)$in['key_id'] : (string)$h['KEY_ID']
                );
                return ['ok' => true, 'id' => $id, 'enabled' => $enabled];
            case 'service':
                $svc = (string)($in['service'] ?? '');
                if (!isset(EdgeHosts::services()[$svc])) {
                    return ['ok' => false, 'error' => 'service must be one of: ' . implode(', ', array_keys(EdgeHosts::services())), 'http' => 400];
                }
                $on = self::truthy($in['service_enabled'] ?? '1');
                EdgeHosts::setPlugin($id, $svc, $on, isset($in['max_inflight']) ? (int)$in['max_inflight'] : null);
                return ['ok' => true, 'id' => $id, 'service' => $svc, 'enabled' => $on];
            case 'test':
                return EdgeHosts::probe($h);
            case 'rotate':
                return ['ok' => true, 'id' => $id, 'secret_next' => EdgeHosts::rotateNext($id),
                    'note' => 'put into host gateway.clients[].secret_next, restart workers, then action=promote'];
            case 'promote':
                return ['ok' => EdgeHosts::promoteNext($id), 'id' => $id];
            case 'delete':
                EdgeHosts::delete($id);
                return ['ok' => true, 'id' => $id, 'deleted' => true];
        }

        return ['ok' => false, 'error' => 'action must be create|update|service|test|rotate|promote|delete', 'http' => 400];
    }

    /** @param mixed $v */
    private static function truthy($v): bool
    {
        return $v === true || $v === 1 || in_array(strtolower(trim((string)$v)), ['1', 'true', 'yes', 'on'], true);
    }
}
