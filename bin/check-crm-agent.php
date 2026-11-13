#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * CRM agent layer: JSON ops invoked in-process like the assistant (route handler + AgentHttp::capture)
 * as a superadmin via Auth::runAs. Quick lead dedupe, PATCH semantics, contacts with roles, kanban,
 * timeline, deal ↔ ticket links, questionnaires (polls JSON, public /q/{code}, stage auto-advance, refill).
 * Throwaway fixtures are deleted at the end.
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
 * @param array<string,scalar|array<int|string,string>> $args
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
        $bag[(string)$k] = is_array($v) ? $v : (string)$v;
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

$created = ['deals' => [], 'companies' => [], 'clients' => [], 'tickets' => [], 'invites' => [], 'polls' => []];
$cleanup = static function () use (&$created, $pdo): void {
    foreach (array_unique(array_filter($created['invites'])) as $iid) {
        $pdo->prepare('DELETE FROM base_poll_invites WHERE ID = ?')->execute([(int)$iid]);
        $pdo->prepare("DELETE FROM base_crm_links WHERE target = 'poll_invite' AND target_id = ?")->execute([(int)$iid]);
    }
    foreach (array_unique(array_filter($created['polls'])) as $rootId) {
        $qs = $pdo->prepare('SELECT ID FROM base_polls WHERE PID = ?');
        $qs->execute([(int)$rootId]);
        foreach ($qs->fetchAll(PDO::FETCH_COLUMN) ?: [] as $qid) {
            $pdo->prepare('DELETE FROM base_polls WHERE PID = ?')->execute([(int)$qid]);
        }
        $pdo->prepare('DELETE FROM base_polls WHERE PID = ? OR ID = ?')->execute([(int)$rootId, (int)$rootId]);
    }
    foreach (array_unique(array_filter($created['tickets'])) as $tid) {
        $pdo->prepare('DELETE FROM base_news WHERE ID = ? AND TYPE = 2')->execute([(int)$tid]);
        $pdo->prepare("DELETE FROM base_crm_links WHERE target = 'ticket' AND target_id = ?")->execute([(int)$tid]);
        if (Database::tableExists('base_gcal_sync_queue')) {
            $pdo->prepare('DELETE FROM base_gcal_sync_queue WHERE ID_TASK = ?')->execute([(int)$tid]);
        }
    }
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

        // Contacts without a phone: Telegram / Max only, matched again by normalized channel.
        $tg = 'crmcheck_' . $suffix;
        $qt = $call('POST', '/crm/deals/quick', [], [
            'company_id' => $companyId, 'contact_name' => 'Tg only ' . $suffix, 'contact_telegram' => '@' . $tg,
            'contact_role' => 'assistant', 'pipeline_id' => $pipeId,
        ]);
        $tgClient = (int)($qt['payload']['client_id'] ?? 0);
        $created['deals'][] = (int)($qt['payload']['deal_id'] ?? 0);
        $created['clients'][] = $tgClient;
        $tgRow = $tgClient > 0 ? ClientsRepository::find($tgClient) : null;
        $tgCh = ClientChannelsRepository::listFor($tgClient);
        $assert($qt['ok'] && !empty($qt['payload']['created']['client']) && $tgRow !== null && (string)$tgRow['tel'] === ''
            && count($tgCh) === 1 && (string)$tgCh[0]['type'] === 'telegram' && (string)$tgCh[0]['href'] === 'https://t.me/' . $tg,
            'quick_create: contact with Telegram only (no phone) ' . $qt['error']);
        $qt2 = $call('POST', '/crm/deals/quick', [], [
            'company_id' => $companyId, 'contact_telegram' => 'https://t.me/' . strtoupper($tg), 'pipeline_id' => $pipeId,
        ]);
        $created['deals'][] = (int)($qt2['payload']['deal_id'] ?? 0);
        $assert($qt2['ok'] && (int)$qt2['payload']['client_id'] === $tgClient && (string)$qt2['payload']['matched']['client'] === 'channel',
            'quick_create matches contact by normalized Telegram link');
        $mx = $call('POST', '/crm/deals/{id}', ['id' => $dealId], [
            'action' => 'add_contact', 'name' => 'Max only ' . $suffix, 'max' => 'max-' . $suffix,
        ]);
        $created['clients'][] = (int)($mx['payload']['client_id'] ?? 0);
        $assert($mx['ok'] && !empty($mx['payload']['client_created']), 'crm.deals.add_contact with Max only');
        $nochan = $call('POST', '/crm/deals/{id}', ['id' => $dealId], ['action' => 'add_contact', 'name' => 'Nobody']);
        $assert(!$nochan['ok'] && $nochan['http'] === 400, 'add_contact without any channel → 400');
        $dgc = (array)($call('GET', '/crm/deals/{id}', ['id' => $dealId])['payload']['contacts'] ?? []);
        $hasMax = false;
        foreach ($dgc as $c) {
            foreach ((array)($c['channels'] ?? []) as $ch) {
                $hasMax = $hasMax || (string)$ch['type'] === 'max';
            }
        }
        $assert($hasMax, 'crm.deals.get contacts[] carry channels');
        $cc = $call('POST', '/clients/new', [], [
            'name' => 'Channels only ' . $suffix, 'enabled' => '1',
            'channels' => [['type' => 'whatsapp', 'value' => '+7 900 ' . substr($suffix, 0, 3) . ' ' . substr($suffix, 3)], ['type' => 'email', 'value' => 'X' . $suffix . '@Example.test']],
        ]);
        $ccId = (int)($cc['payload']['id'] ?? 0);
        $created['clients'][] = $ccId;
        $assert($cc['ok'] && count((array)($cc['payload']['item']['channels'] ?? [])) === 2, 'clients.create with channels only ' . $cc['error']);
        $cu2 = $call('POST', '/clients/{id}/edit', ['id' => $ccId], ['name' => 'Renamed ' . $suffix, 'enabled' => '1', 'mail' => '']);
        $assert($cu2['ok'] && count(ClientChannelsRepository::listFor($ccId)) === 2, 'clients.update without channels keeps them');

        if (CrmTickets::available()) {
            $tk = $call('POST', '/crm/deals/{id}', ['id' => $dealId], [
                'action' => 'add_ticket', 'name' => 'Meeting ' . $suffix, 'deadline' => date('Y-m-d', time() + 86400),
                'body' => 'agenda',
            ]);
            $ticketId = (int)($tk['payload']['ticket_id'] ?? 0);
            $created['tickets'][] = $ticketId;
            $ticket = $ticketId > 0 ? NewsRepository::find($ticketId) : null;
            $assert($tk['ok'] && $ticket !== null && (int)$ticket['TYPE'] === 2
                && NewsRepository::parseUserList((string)$ticket['USERS_RESP']) === [$uid],
                'crm.deals.add_ticket creates TYPE=2 ticket, assignee = deal responsible ' . $tk['error']);
            $dg = $call('GET', '/crm/deals/{id}', ['id' => $dealId]);
            $tks = (array)($dg['payload']['tickets'] ?? []);
            $assert(count($tks) === 1 && (int)$tks[0]['id'] === $ticketId && (int)$tks[0]['status_id'] > 0,
                'crm.deals.get tickets[] with status');
            $assert(in_array('ticket', array_column((array)$dg['payload']['timeline'], 'type'), true), 'timeline has ticket');
            $news = $call('GET', '/news/{id}', ['id' => $ticketId], ['comments' => '0']);
            $ext = (array)($news['payload']['extensions']['crm']['deals'] ?? []);
            $assert($news['ok'] && count($ext) === 1 && (int)$ext[0]['id'] === $dealId,
                'core.news.get extensions.crm.deals points back to the deal');
            $other = (int)($q2['payload']['deal_id'] ?? 0);
            $lk = $call('POST', '/crm/deals/{id}', ['id' => $other], ['action' => 'link_ticket', 'ticket_id' => $ticketId]);
            $assert($lk['ok'] && count(CrmTickets::dealsForTicket($ticketId)) === 2, 'crm.deals.link_ticket (one ticket, two deals)');
            $ul = $call('POST', '/crm/deals/{id}', ['id' => $other], ['action' => 'unlink_ticket', 'ticket_id' => $ticketId]);
            $assert($ul['ok'] && count(CrmTickets::dealsForTicket($ticketId)) === 1 && NewsRepository::find($ticketId) !== null,
                'crm.deals.unlink_ticket keeps the ticket');
            $lb = $call('POST', '/crm/deals/{id}', ['id' => $dealId], ['action' => 'link_ticket', 'ticket_id' => 999999999]);
            $assert(!$lb['ok'] && $lb['http'] === 400, 'link_ticket rejects unknown ticket');
        } else {
            echo "SKIP tickets (core news unavailable)\n";
        }

        if (CrmPolls::available()) {
            $root = $call('POST', '/polls/new', [], ['el_type' => 0, 'name' => 'B2B check ' . $suffix, 'descr_site' => 'Hello {client.name}']);
            $rootId = (int)($root['payload']['id'] ?? 0);
            $created['polls'][] = $rootId;
            $assert($root['ok'] && $rootId > 0, 'polls.create root over JSON ' . $root['error']);
            $q1 = (int)($call('POST', '/polls/new', [], ['el_type' => 1, 'pid' => $rootId, 'name' => 'Cabinets?', 'num' => 1])['payload']['id'] ?? 0);
            $o1 = (int)($call('POST', '/polls/new', [], ['el_type' => 2, 'pid' => $q1, 'name' => '1-3', 'num' => 1])['payload']['id'] ?? 0);
            $call('POST', '/polls/new', [], ['el_type' => 2, 'pid' => $q1, 'name' => '4+', 'num' => 2]);
            $q2 = (int)($call('POST', '/polls/new', [], ['el_type' => 1, 'pid' => $rootId, 'name' => 'Pains at {company.name}?', 'num' => 2])['payload']['id'] ?? 0);
            $q3 = (int)($call('POST', '/polls/new', [], ['el_type' => 1, 'pid' => $rootId, 'name' => 'Skipped', 'num' => 3])['payload']['id'] ?? 0);
            $assert($q1 > 0 && $o1 > 0 && $q2 > 0 && $q3 > 0, 'polls.create questions + options');
            $up = $call('POST', '/polls/{id}/edit', ['id' => $q1], ['descr_site' => 'rooms']);
            $q1row = PollsRepository::find($q1);
            $assert($up['ok'] && (string)$q1row['name'] === 'Cabinets?' && (int)$q1row['enabled'] === 1, 'polls.update PATCH keeps name/enabled');

            $sp = $call('POST', '/crm/deals/{id}', ['id' => $dealId], [
                'action' => 'send_poll', 'poll_id' => $rootId, 'text' => '{client.name}, about {company.name}',
                'skip_qids' => (string)$q3,
            ]);
            $inviteId = (int)($sp['payload']['invite_id'] ?? 0);
            $created['invites'][] = $inviteId;
            $url = (string)($sp['payload']['url'] ?? '');
            $code = (string)substr($url, strrpos($url, '/') + 1);
            $assert($sp['ok'] && strlen($code) === PollPublic::CODE_LEN, 'crm.deals.send_poll returns public link ' . $sp['error']);
            $assert((string)CrmDealsRepository::find($dealId)['stage_code'] === 'QUESTIONNAIRE_SENT', 'send_poll moves deal to QUESTIONNAIRE_SENT');

            $pub = $call('GET', '/q/{code}', ['code' => $code]);
            $qids = array_column((array)($pub['payload']['questions'] ?? []), 'qid');
            $assert($pub['ok'] && $qids === [$q1, $q2], 'public GET: personal skip_qids applied');
            $assert(strpos((string)($pub['payload']['intro'] ?? ''), 'CRM check clinic ' . $suffix) !== false
                && strpos((string)($pub['payload']['questions'][1]['name'] ?? ''), 'CRM check clinic') !== false,
                'public GET: {company.name} placeholder from CRM');
            $short = $call('GET', '/q/{code}', ['code' => substr($code, 0, 8)]);
            $assert(!$short['ok'] && $short['http'] === 404, 'public GET: short code → 404');

            $draft = $call('POST', '/q/{code}', ['code' => $code], ['answers' => [$q1 => (string)$o1]]);
            $assert($draft['ok'] && empty($draft['payload']['completed']) && (int)$draft['payload']['missing_qid'] === $q2,
                'public POST partial → draft, missing = free-text question');
            $done = $call('POST', '/q/{code}', ['code' => $code], ['answers' => [$q1 => (string)$o1], 'texts' => [$q2 => 'no CRM']]);
            $assert($done['ok'] && !empty($done['payload']['completed']), 'public POST complete (text-only question advances walk)');
            $assert((string)CrmDealsRepository::find($dealId)['stage_code'] === 'QUESTIONNAIRE_DONE', 'completion moves deal to QUESTIONNAIRE_DONE');
            $dp = (array)($call('GET', '/crm/deals/{id}', ['id' => $dealId])['payload']['polls'] ?? []);
            $assert(count($dp) === 1 && (string)$dp[0]['state'] === 'completed' && count($dp[0]['answers']) === 2,
                'crm.deals.get polls[] completed with answers');

            $rf = $call('POST', '/crm/deals/{id}', ['id' => $dealId], ['action' => 'refill_poll', 'invite_id' => $inviteId]);
            $newInvite = (int)($rf['payload']['invite_id'] ?? 0);
            $created['invites'][] = $newInvite;
            $ni = $newInvite > 0 ? PollInvitesRepository::find($newInvite) : null;
            $assert($rf['ok'] && $ni !== null && (string)$ni['data_text'] !== '{}' && (int)$ni['dt_completed'] === 0
                && count(CrmPolls::forDeal($dealId)) === 2, 'crm.deals.refill_poll: new linked invite, answers prefilled');
            $assert(PollPublic::personal($ni)['skip_qids'] === [$q3], 'refill keeps personal skip_qids');
        } else {
            echo "SKIP polls (poll_invites disabled)\n";
        }

        $bad = $call('POST', '/crm/deals/{id}', ['id' => $dealId], ['action' => 'nope']);
        $assert(!$bad['ok'] && $bad['http'] === 400, 'unknown action → 400');
    });
} finally {
    $cleanup();
}

echo $failed === 0 ? "OK  crm agent layer\n" : "FAIL crm agent layer ($failed)\n";
exit($failed === 0 ? 0 : 1);
