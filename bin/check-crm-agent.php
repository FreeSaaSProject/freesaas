#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * CRM agent layer: JSON ops invoked in-process like the assistant (route handler + AgentHttp::capture)
 * as a superadmin via Auth::runAs. Quick lead dedupe, PATCH semantics, contacts with roles, kanban,
 * timeline. Throwaway fixtures are deleted at the end.
 * Exit: 0 ok, 1 fail; SKIP if crm/clients are off or no superadmin exists.
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';

$failed = 0;
$assert = static function (bool $ok, string $msg) use (&$failed): void {
    echo ($ok ? 'OK  ' : 'FAIL ') . $msg . "\n";
    if (!$ok) {
        $failed++;
    }
};

if (!ModuleManager::isEnabled('crm') || !ModuleManager::isEnabled('clients')
    || !class_exists('CrmDealsRepository', false) || !class_exists('ClientsRepository', false)
) {
    echo "SKIP crm/clients disabled\n";
    exit(0);
}

$pdo = Database::pdo();
$uid = (int)$pdo->query(
    "SELECT u.id FROM base_users u JOIN base_admin_statuses s ON s.ID = u.ID_STATUS
     WHERE s.SUPERADMIN_ACCESS > 0 AND u.enabled = '1' ORDER BY u.id LIMIT 1"
)->fetchColumn();
if ($uid < 1) {
    echo "SKIP no enabled superadmin\n";
    exit(0);
}

/**
 * @param array<string,scalar> $pathArgs
 * @param array<string,scalar> $args
 * @return array{ok:bool,http:int,payload:?array,error:string}
 */
$call = static function (string $method, string $pattern, array $pathArgs = [], array $args = []): array {
    $resolved = Router::resolvePattern($pattern, $pathArgs);
    if ($resolved === null) {
        return ['ok' => false, 'http' => 404, 'payload' => null, 'error' => 'route not registered: ' . $pattern];
    }
    $route = $resolved['route'];
    $perm = $route['permission'] ?? null;
    if (is_string($perm) && $perm !== '' && !Auth::can($perm)) {
        return ['ok' => false, 'http' => 403, 'payload' => null, 'error' => 'missing permission ' . $perm];
    }
    $bag = [];
    foreach ($args as $k => $v) {
        $bag[(string)$k] = (string)$v;
    }
    $prev = [$_GET, $_POST, $_REQUEST, $_SERVER];
    $_GET = $method === 'GET' ? $bag + ['format' => 'json'] : ['format' => 'json'];
    $_POST = $method === 'GET' ? [] : $bag + ['format' => 'json'];
    $_REQUEST = array_merge($_GET, $_POST);
    $_SERVER['REQUEST_METHOD'] = $method;
    $_SERVER['HTTP_ACCEPT'] = 'application/json';
    $res = AgentHttp::capture(static function () use ($route, $resolved): void {
        Router::invokeHandler($route['handler'], $resolved['params'], $route);
    });
    [$_GET, $_POST, $_REQUEST, $_SERVER] = $prev;
    return [
        'ok' => $res['ok'],
        'http' => (int)$res['http'],
        'payload' => $res['payload'],
        'error' => (string)$res['error'],
    ];
};

$created = ['deals' => [], 'companies' => [], 'clients' => []];
$cleanup = static function () use (&$created, $pdo): void {
    foreach (array_unique($created['deals']) as $id) {
        CrmDealsRepository::delete((int)$id);
        $pdo->prepare("DELETE FROM base_crm_notes WHERE entity = 'deal' AND entity_id = ?")->execute([(int)$id]);
        $pdo->prepare("DELETE FROM base_crm_custom_values WHERE entity = 'deal' AND entity_id = ?")->execute([(int)$id]);
    }
    foreach (array_unique($created['companies']) as $id) {
        CrmCompaniesRepository::delete((int)$id);
        $pdo->prepare("DELETE FROM base_crm_notes WHERE entity = 'company' AND entity_id = ?")->execute([(int)$id]);
    }
    if ($created['clients'] !== []) {
        ClientsRepository::deleteByIds(array_values(array_unique(array_map('intval', $created['clients']))));
    }
};

$suffix = (string)random_int(100000, 999999);
$inn = '77' . $suffix . '01';
$telA = '79' . str_pad((string)random_int(0, 999999999), 9, '0', STR_PAD_LEFT);
$telB = '79' . str_pad((string)random_int(0, 999999999), 9, '0', STR_PAD_LEFT);

try {
    Auth::runAs($uid, static function () use ($call, $assert, &$created, $inn, $telA, $telB, $suffix, $uid): void {
        $form = $call('GET', '/crm/deals/quick');
        $assert($form['ok'], 'crm.deals.quick_form');
        $pipeId = 0;
        foreach ((array)($form['payload']['pipelines'] ?? []) as $p) {
            if ((string)$p['name'] === 'B2B') {
                $pipeId = (int)$p['id'];
            }
        }
        $assert($pipeId > 0, 'B2B pipeline seeded');
        $stages = [];
        foreach ((array)($form['payload']['stages_by_pipeline'][$pipeId] ?? []) as $s) {
            $stages[(string)$s['code']] = (int)$s['id'];
        }
        $assert(isset($stages['MEETING'], $stages['QUESTIONNAIRE_DONE']), 'B2B stages present');

        $q = $call('POST', '/crm/deals/quick', [], [
            'company_name' => 'CRM check clinic ' . $suffix,
            'company_inn' => $inn,
            'contact_name' => 'Anna ' . $suffix,
            'contact_tel' => $telA,
            'contact_role' => 'contact person',
            'pipeline_id' => $pipeId,
            'amount' => '1234',
            'source' => 'referral',
        ]);
        $assert($q['ok'] && !empty($q['payload']['created']['company']) && !empty($q['payload']['created']['client']),
            'crm.deals.quick_create creates company + contact + deal ' . $q['error']);
        $dealId = (int)($q['payload']['deal_id'] ?? 0);
        $companyId = (int)($q['payload']['company_id'] ?? 0);
        $clientA = (int)($q['payload']['client_id'] ?? 0);
        $created['deals'][] = $dealId;
        $created['companies'][] = $companyId;
        $created['clients'][] = $clientA;

        $q2 = $call('POST', '/crm/deals/quick', [], [
            'company_name' => 'other name',
            'company_inn' => $inn,
            'contact_tel' => '8' . substr($telA, 1),
            'pipeline_id' => $pipeId,
        ]);
        $created['deals'][] = (int)($q2['payload']['deal_id'] ?? 0);
        $assert($q2['ok'] && (int)$q2['payload']['company_id'] === $companyId && (string)$q2['payload']['matched']['company'] === 'inn',
            'quick_create matches company by INN');
        $assert((int)($q2['payload']['client_id'] ?? 0) === $clientA && empty($q2['payload']['created']['client']),
            'quick_create matches contact by phone (8… → 7…)');

        $get = $call('GET', '/crm/deals/{id}', ['id' => $dealId]);
        $item = (array)($get['payload']['item'] ?? []);
        $assert($get['ok'] && (float)$item['amount'] === 1234.0 && (string)$item['company_name'] !== '', 'crm.deals.get item');
        $contacts = (array)($get['payload']['contacts'] ?? []);
        $assert(count($contacts) === 1 && (string)$contacts[0]['role'] === 'contact person' && !empty($contacts[0]['is_primary']),
            'deal contact carries company role + primary');

        $u = $call('POST', '/crm/deals/{id}/edit', ['id' => $dealId], ['responsible_user_id' => $uid]);
        $row = CrmDealsRepository::find($dealId);
        $assert($u['ok'] && (float)$row['amount'] === 1234.0 && (string)$row['title'] === 'CRM check clinic ' . $suffix
            && (int)$row['responsible_user_id'] === $uid && (string)$row['source'] === 'referral',
            'crm.deals.update PATCH (responsible only) keeps title/amount/source');
        $assert(count(CrmDealsRepository::contactLinks($dealId)) === 1, 'PATCH without client_ids keeps contacts');

        $mv = $call('POST', '/crm/deals/{id}', ['id' => $dealId], ['action' => 'move_stage', 'stage_id' => $stages['MEETING']]);
        $assert($mv['ok'] && (int)CrmDealsRepository::find($dealId)['stage_id'] === $stages['MEETING'], 'crm.deals.move_stage');
        $note = $call('POST', '/crm/deals/{id}', ['id' => $dealId], ['action' => 'add_note', 'body' => 'meeting agreed']);
        $assert($note['ok'], 'crm.deals.add_note');

        $tl = (array)($call('GET', '/crm/deals/{id}', ['id' => $dealId])['payload']['timeline'] ?? []);
        $types = array_column($tl, 'type');
        $assert(in_array('note', $types, true) && in_array('stage', $types, true), 'timeline has note + stage');
        $assert((string)($tl[0]['author_name'] ?? '') !== '', 'timeline items carry author_name');

        $kb = $call('GET', '/crm/deals', [], ['view' => 'kanban', 'pipeline_id' => $pipeId, 'responsible' => 'me']);
        $col = null;
        foreach ((array)($kb['payload']['columns'] ?? []) as $c) {
            if ((int)$c['stage_id'] === $stages['MEETING']) {
                $col = $c;
            }
        }
        $assert($kb['ok'] && $col !== null && in_array($dealId, array_map('intval', array_column($col['items'], 'id')), true)
            && (float)($col['sums']['RUB'] ?? 0) >= 1234.0, 'crm.deals.list view=kanban (responsible=me) column + sums');

        $add = $call('POST', '/crm/deals/{id}', ['id' => $dealId], [
            'action' => 'add_contact', 'tel' => $telB, 'name' => 'Boss ' . $suffix,
            'role' => 'decision maker', 'link_company' => '1',
        ]);
        $created['clients'][] = (int)($add['payload']['client_id'] ?? 0);
        $assert($add['ok'] && !empty($add['payload']['client_created']), 'crm.deals.add_contact by phone creates client');
        $cc = CrmCompaniesRepository::contactLinks($companyId);
        $roles = array_column($cc, 'role', 'client_id');
        $assert(count($cc) === 2 && ($roles[(int)$add['payload']['client_id']] ?? '') === 'decision maker',
            'link_company=1 links contact to company with role');

        $rl = $call('POST', '/crm/companies/{id}', ['id' => $companyId], [
            'action' => 'set_contact_role', 'client_id' => $clientA, 'role' => 'LPR',
        ]);
        $assert($rl['ok'], 'crm.companies.set_contact_role');
        $cu = $call('POST', '/crm/companies/{id}/edit', ['id' => $companyId], ['website' => 'example.test']);
        $co = CrmCompaniesRepository::find($companyId);
        $roles = array_column(CrmCompaniesRepository::contactLinks($companyId), 'role', 'client_id');
        $assert($cu['ok'] && (string)$co['inn'] === $inn && (string)$co['website'] === 'example.test'
            && ($roles[$clientA] ?? '') === 'LPR' && count($roles) === 2,
            'crm.companies.update PATCH keeps INN, contacts and roles');

        $pr = $call('POST', '/crm/companies/{id}', ['id' => $companyId], [
            'action' => 'set_primary_contact', 'client_id' => (int)$add['payload']['client_id'],
        ]);
        $prim = array_column(CrmCompaniesRepository::contactLinks($companyId), 'is_primary', 'client_id');
        $assert($pr['ok'] && (int)$prim[(int)$add['payload']['client_id']] === 1 && (int)$prim[$clientA] === 0,
            'crm.companies.set_primary_contact');

        $rm = $call('POST', '/crm/deals/{id}', ['id' => $dealId], ['action' => 'remove_contact', 'client_id' => $clientA]);
        $links = CrmDealsRepository::contactLinks($dealId);
        $assert($rm['ok'] && count($links) === 1 && !empty($links[0]['is_primary']), 'crm.deals.remove_contact keeps a primary');

        $bad = $call('POST', '/crm/deals/{id}', ['id' => $dealId], ['action' => 'nope']);
        $assert(!$bad['ok'] && $bad['http'] === 400, 'unknown action → 400');
    });
} finally {
    $cleanup();
}

echo $failed === 0 ? "OK  crm agent layer\n" : "FAIL crm agent layer ($failed)\n";
exit($failed === 0 ? 0 : 1);
