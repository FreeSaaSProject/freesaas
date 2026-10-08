<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$id = (int)Router::param('id');
$conn = BankConnectionsRepository::find($id);
if (!$conn) {
    if ($wantsJson) {
        AgentHttp::error(t('banks.err.not_found'), 404);
    }
    flash_set('error', t('banks.err.not_found'));
    redirect('/banks');
}

$canConnect = Auth::can('banks.connect');
$canSync = Auth::can('banks.sync');
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canConnect) {
        if ($wantsJson) {
            AgentHttp::error(t('banks.err.link'), 403);
        }
        flash_set('error', t('banks.err.link'));
        redirect('/banks/connections/' . $id);
    }
    $action = trim((string)($_POST['action'] ?? ''));
    try {
        if ($action === 'refresh') {
            foreach (BankOAuth::fetchRemoteAccounts($id) as $remote) {
                BankAccountLinksRepository::upsertRemote($id, $remote);
            }
            $flash = ['ok' => true, 'action' => 'refresh'];
        } elseif ($action === 'link') {
            $ext = trim((string)($_POST['external_account_id'] ?? ''));
            $accountId = (int)($_POST['account_id'] ?? 0);
            $link = BankAccountLinksRepository::findByExternal($id, $ext);
            if (!$link || $accountId < 1 || !LedgerAccountsRepository::find($accountId)) {
                throw new InvalidArgumentException(t('banks.err.link'));
            }
            BankAccountLinksRepository::linkToLedger((int)$link['id'], $accountId);
            $flash = ['ok' => true, 'action' => 'link', 'link_id' => (int)$link['id'], 'account_id' => $accountId];
        } elseif ($action === 'unlink') {
            $linkId = (int)($_POST['link_id'] ?? 0);
            BankAccountLinksRepository::unlink($linkId);
            $flash = ['ok' => true, 'action' => 'unlink', 'link_id' => $linkId];
        } elseif ($action === 'create_ledger') {
            $ext = trim((string)($_POST['external_account_id'] ?? ''));
            $link = BankAccountLinksRepository::findByExternal($id, $ext);
            if (!$link) {
                throw new InvalidArgumentException(t('banks.err.link'));
            }
            $name = trim((string)($_POST['account_name'] ?? ''));
            if ($name === '') {
                $name = (string)$link['account_number'] !== ''
                    ? mb_substr((string)$link['account_number'], -20)
                    : mb_substr((string)$link['external_account_id'], 0, 20);
            }
            $accountId = LedgerAccountsRepository::create([
                'name' => $name,
                'descr' => (string)($link['remote_name'] ?? BanksConfig::providerLabel((string)$conn['provider'])),
                'enabled' => 1,
                'id_type' => 2,
                'dt' => time(),
            ]);
            BankAccountLinksRepository::linkToLedger((int)$link['id'], $accountId);
            $flash = ['ok' => true, 'action' => 'create_ledger', 'account_id' => $accountId, 'link_id' => (int)$link['id']];
        } else {
            throw new InvalidArgumentException(t('banks.err.link'));
        }
        if ($wantsJson) {
            AgentHttp::emit($flash);
        }
        flash_set('ok', t('core.common.saved'));
        redirect('/banks/connections/' . $id);
    } catch (Throwable $e) {
        if ($wantsJson) {
            AgentHttp::error($e->getMessage(), 400);
        }
        flash_set('error', $e->getMessage());
        redirect('/banks/connections/' . $id);
    }
}

$links = BankAccountLinksRepository::forConnection($id);
$ledgerAccounts = LedgerAccountsRepository::names(true);
$lastSync = null;
$metaRaw = $conn['meta'] ?? null;
$meta = [];
if (is_string($metaRaw) && $metaRaw !== '') {
    $decoded = json_decode($metaRaw, true);
    if (is_array($decoded)) {
        $meta = $decoded;
    }
} elseif (is_array($metaRaw)) {
    $meta = $metaRaw;
}
if (isset($meta['last_sync']) && is_array($meta['last_sync'])) {
    $lastSync = $meta['last_sync'];
}

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'connection' => $conn,
        'links' => $links,
        'ledger_accounts' => $ledgerAccounts,
        'last_sync' => $lastSync,
        'can_connect' => $canConnect,
        'can_sync' => $canSync,
    ]);
}

View::render('connection', [
    'title' => t('banks.conn.title') . ' · ' . BanksConfig::providerLabel((string)$conn['provider']),
    'conn' => $conn,
    'links' => $links,
    'ledgerAccounts' => $ledgerAccounts,
    'lastSync' => $lastSync,
    'canConnect' => $canConnect,
    'canSync' => $canSync,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('banks.menu.list'), 'href' => '/banks'],
        ['label' => BanksConfig::providerLabel((string)$conn['provider']), 'current' => true],
    ]),
], 'layout', 'banks');
