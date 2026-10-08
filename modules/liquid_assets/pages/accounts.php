<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$filters = [];
BulkActions::tryHandle('liquid_assets_accounts', '/liquid-assets/accounts', $filters);

$items = TinvestAccountsRepository::all();
$fundNames = [];
if (TinvestSyncService::fundsEnabled() && class_exists('FundsRepository', false)) {
    $fundNames = FundsRepository::names();
}
$userNames = class_exists('UsersRepository', false) ? UsersRepository::staffNamesIncluding([]) : [];

$outItems = [];
foreach ($items as $row) {
    $fundId = (int)$row['fund_id'];
    $userId = (int)$row['user_id'];
    $outItems[] = [
        'id' => (int)$row['id'],
        'fund_id' => $fundId,
        'fund_name' => $fundId > 0 && isset($fundNames[$fundId])
            ? ltrim((string)$fundNames[$fundId], '— ')
            : '',
        'user_id' => $userId,
        'user_name' => $userNames[$userId] ?? ('#' . $userId),
        'token_mask' => TinvestAccountsRepository::maskToken($row['token']),
        'label' => TinvestAccountsRepository::label($row, $fundNames),
    ];
}

if ($wantsJson) {
    AgentHttp::emit(['ok' => true, 'items' => $outItems, 'total' => count($outItems)]);
    return;
}

View::render('accounts', [
    'title' => t('liquid_assets.accounts.title'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('liquid_assets.menu.report'), 'href' => '/liquid-assets'],
        ['label' => t('liquid_assets.menu.accounts'), 'current' => true],
    ]),
    'items' => $outItems,
    'canEdit' => Auth::can('liquid_assets.edit'),
    'bulkFlash' => BulkActions::consumeFlash(),
], 'layout', 'liquid_assets');
