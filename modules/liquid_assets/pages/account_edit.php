<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$id = (int)(Router::param('id') ?? 0);
$isNew = $id < 1;
$row = ['id' => 0, 'fund_id' => 0, 'user_id' => Auth::userId(), 'token' => ''];
if (!$isNew) {
    $found = TinvestAccountsRepository::find($id);
    if (!$found) {
        if ($wantsJson) {
            AgentHttp::error(t('liquid_assets.accounts.err.not_found', ['id' => $id]), 404);
            return;
        }
        http_response_code(404);
        View::render('error', [
            'title' => t('core.common.not_found'),
            'message' => t('liquid_assets.accounts.err.not_found', ['id' => $id]),
        ]);
        return;
    }
    $row = $found;
}

$message = '';
$error = '';

if (!$isNew && EntityDelete::tryHandle('liquid_assets_accounts', $id, '/liquid-assets/accounts', [
    'stay_path' => '/liquid-assets/accounts/' . $id . '/edit',
])) {
    return;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $data = [
            'fund_id' => (int)($_POST['fund_id'] ?? 0),
            'user_id' => (int)($_POST['user_id'] ?? Auth::userId()),
            'token' => (string)($_POST['token'] ?? ''),
        ];
        if ($isNew) {
            $newId = TinvestAccountsRepository::create($data);
            if ($wantsJson) {
                AgentHttp::emit([
                    'ok' => true,
                    'id' => $newId,
                    'item' => TinvestAccountsRepository::toAgentItem(TinvestAccountsRepository::find($newId)),
                ]);
                return;
            }
            redirect('/liquid-assets/accounts/' . $newId . '/edit?saved=1');
            return;
        }
        // empty token on edit = keep existing
        if (trim($data['token']) === '') {
            unset($data['token']);
        }
        TinvestAccountsRepository::update($id, $data);
        $row = TinvestAccountsRepository::find($id) ?: $row;
        if ($wantsJson) {
            AgentHttp::emit([
                'ok' => true,
                'id' => $id,
                'item' => TinvestAccountsRepository::toAgentItem($row),
            ]);
            return;
        }
        $message = t('core.common.saved');
    } catch (Throwable $e) {
        if ($wantsJson) {
            AgentHttp::error($e->getMessage(), 400);
            return;
        }
        $error = $e->getMessage();
        $row = array_merge($row, [
            'fund_id' => (int)($_POST['fund_id'] ?? $row['fund_id']),
            'user_id' => (int)($_POST['user_id'] ?? $row['user_id']),
        ]);
    }
}
if (isset($_GET['saved']) && $message === '') {
    $message = t('core.common.saved');
}

$fundOptions = [0 => t('liquid_assets.accounts.fund_none')];
if (TinvestSyncService::fundsEnabled() && class_exists('FundsRepository', false)) {
    foreach (FundsRepository::names() as $fid => $fname) {
        // leaf funds only (PID>0) like oper
        $f = FundsRepository::find((int)$fid);
        if ($f && (int)$f['pid'] > 0) {
            $fundOptions[(int)$fid] = $fname;
        }
    }
}
$userOptions = class_exists('UsersRepository', false)
    ? UsersRepository::staffNamesIncluding([(int)$row['user_id']])
    : [];

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'is_new' => $isNew,
        'item' => TinvestAccountsRepository::toAgentItem($row),
        'funds' => $fundOptions,
        'users' => $userOptions,
        'funds_on' => TinvestSyncService::fundsEnabled(),
    ]);
    return;
}

View::render('account_edit', [
    'title' => $isNew
        ? t('liquid_assets.accounts.edit_new')
        : t('liquid_assets.accounts.edit', ['id' => $id]),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('liquid_assets.menu.report'), 'href' => '/liquid-assets'],
        ['label' => t('liquid_assets.menu.accounts'), 'href' => '/liquid-assets/accounts'],
        ['label' => $isNew ? t('liquid_assets.accounts.edit_new') : ('#' . $id), 'current' => true],
    ]),
    'isNew' => $isNew,
    'row' => $row,
    'fundOptions' => $fundOptions,
    'userOptions' => $userOptions,
    'fundsOn' => TinvestSyncService::fundsEnabled(),
    'message' => $message,
    'error' => $error,
], 'layout', 'liquid_assets');
