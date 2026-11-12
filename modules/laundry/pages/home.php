<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$panelError = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    if (LaundryPanel::isPanelAction($action)) {
        try {
            LaundryPanel::handlePost($action, $_POST, Auth::userId(), LaundryConfig::ticketNewsId());
            if ($wantsJson) {
                AgentHttp::emit(['ok' => true, 'action' => $action]);
                return;
            }
            redirect('/laundry?saved=1#laundry');
            return;
        } catch (Throwable $e) {
            if ($wantsJson) {
                AgentHttp::error($e->getMessage(), 400);
                return;
            }
            $panelError = $e->getMessage();
        }
    }
}

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'started' => LaundryMovesRepository::hasAnyMoves(),
        'balances' => LaundryMovesRepository::balanceMatrix(),
        'accounts' => LaundryAccountsRepository::names(true),
        'types' => LaundryItemTypesRepository::listEnabled(),
        'open_shipments' => LaundryShipmentsRepository::listOpenWithLines(),
    ]);
    return;
}

View::render('home', [
    'title' => t('laundry.menu.home'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('laundry.menu.home'), 'current' => true],
    ]),
    'panelHtml' => LaundryPanel::render(['post_url' => '/laundry', 'error' => $panelError]),
    'message' => isset($_GET['saved']) ? t('core.common.saved') : '',
    'canEdit' => Auth::can('laundry.edit'),
], 'layout', 'laundry');
