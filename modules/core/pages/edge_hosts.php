<?php
declare(strict_types=1);

$onceKey = 'core_edge_hosts_once';

$ready = EdgeHosts::available();
$services = EdgeHosts::services();

if ($ready && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $warnings = [];
    $rows = is_array($_POST['hosts'] ?? null) ? $_POST['hosts'] : [];
    foreach ($rows as $id => $row) {
        $id = (int)$id;
        $host = EdgeHosts::find($id);
        if (!is_array($row) || $host === null) {
            continue;
        }
        $url = trim((string)($row['base_url'] ?? ''));
        $pin = trim((string)($row['pin'] ?? ''));
        $enabled = !empty($row['enabled']);
        if ($enabled && (!EdgeHostClient::validBaseUrl($url) || !EdgeHostClient::validPin($pin))) {
            $enabled = false;
            $warnings[] = t('core.edge_hosts.warn_cannot_enable', ['name' => (string)($row['name'] ?? $id)]);
        }
        EdgeHosts::update($id, (string)($row['name'] ?? ''), $url, $pin, $enabled, (string)($row['key_id'] ?? 'owner'));
        $svcRows = is_array($row['services'] ?? null) ? $row['services'] : [];
        foreach ($services as $svc => $def) {
            $s = is_array($svcRows[$svc] ?? null) ? $svcRows[$svc] : [];
            EdgeHosts::setPlugin($id, $svc, !empty($s['enabled']), (int)($s['max_inflight'] ?? 0));
        }
    }

    $once = $warnings !== [] ? ['kind' => 'warn', 'text' => implode(' ', $warnings)] : ['kind' => 'ok', 'text' => t('core.edge_hosts.saved')];
    [$verb, $rawId] = array_pad(explode(':', (string)($_POST['edge_action'] ?? ''), 2), 2, '0');
    $id = (int)$rawId;
    if ($verb === 'add') {
        $new = is_array($_POST['new'] ?? null) ? $_POST['new'] : [];
        $name = trim((string)($new['name'] ?? ''));
        $url = trim((string)($new['base_url'] ?? ''));
        $pin = trim((string)($new['pin'] ?? ''));
        if ($name === '' || !EdgeHostClient::validBaseUrl($url)) {
            $once = ['kind' => 'warn', 'text' => t('core.edge_hosts.warn_bad_url')];
        } else {
            if ($pin !== '' && !EdgeHostClient::validPin($pin)) {
                $pin = '';
            }
            $plugins = [];
            $newSvc = is_array($new['services'] ?? null) ? $new['services'] : [];
            foreach ($services as $svc => $def) {
                $plugins[$svc] = ['enabled' => !empty($newSvc[$svc])];
            }
            $c = EdgeHosts::create($name, $url, $pin, $plugins, (string)($new['key_id'] ?? 'owner'));
            $once = ['kind' => 'secret', 'name' => $name, 'secret' => $c['secret'], 'field' => 'secret'];
        }
    } elseif (($host = EdgeHosts::find($id)) !== null) {
        switch ($verb) {
            case 'test':
                $r = EdgeHosts::probe($host);
                $once = [
                    'kind' => $r['ok'] ? 'ok' : 'warn',
                    'text' => t($r['ok'] ? 'core.edge_hosts.test_ok' : 'core.edge_hosts.test_fail', [
                        'name' => (string)$host['NAME'],
                        'detail' => $r['detail'],
                    ]),
                ];
                break;
            case 'rotate':
                $once = ['kind' => 'secret', 'name' => (string)$host['NAME'], 'secret' => EdgeHosts::rotateNext($id), 'field' => 'secret_next'];
                break;
            case 'promote':
                $ok = EdgeHosts::promoteNext($id);
                $once = ['kind' => $ok ? 'ok' : 'warn', 'text' => t($ok ? 'core.edge_hosts.promote_ok' : 'core.edge_hosts.promote_none')];
                break;
            case 'delete':
                EdgeHosts::delete($id);
                $once = ['kind' => 'ok', 'text' => t('core.edge_hosts.deleted', ['name' => (string)$host['NAME']])];
                break;
        }
    }
    $_SESSION[$onceKey] = $once;
    redirect('/edge-hosts');
}

$once = $_SESSION[$onceKey] ?? null;
unset($_SESSION[$onceKey]);

View::render('edge_hosts', [
    'title' => t('core.menu.edge_hosts'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('core.menu.edge_hosts'), 'current' => true],
    ]),
    'ready' => $ready,
    'hosts' => $ready ? EdgeHosts::all() : [],
    'services' => $services,
    'once' => is_array($once) ? $once : null,
], 'layout', 'core');
