<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/LaundryConfig.php';
require_once __DIR__ . '/lib/LaundryAccountsRepository.php';
require_once __DIR__ . '/lib/LaundryItemTypesRepository.php';
require_once __DIR__ . '/lib/LaundryMovesRepository.php';
require_once __DIR__ . '/lib/LaundryShipmentsRepository.php';
require_once __DIR__ . '/lib/LaundryInventory.php';

$pages = [
    '/laundry' => ['permission' => 'laundry.view', 'methods' => ['GET'], 'file' => 'home.php'],
    '/laundry/moves' => ['permission' => 'laundry.view', 'methods' => ['GET'], 'file' => 'moves_list.php'],
    '/laundry/shipments' => ['permission' => 'laundry.view', 'methods' => ['GET'], 'file' => 'shipments_list.php'],
    '/laundry/ship' => ['permission' => 'laundry.ship', 'methods' => ['GET', 'POST'], 'file' => 'ship.php'],
    '/laundry/accept' => ['permission' => 'laundry.ship', 'methods' => ['GET', 'POST'], 'file' => 'accept.php'],
    '/laundry/inventory' => ['permission' => 'laundry.edit', 'methods' => ['GET', 'POST'], 'file' => 'inventory.php'],
    '/laundry/accounts' => ['permission' => 'laundry.accounts', 'methods' => ['GET', 'POST'], 'file' => 'accounts_list.php'],
    '/laundry/accounts/new' => ['permission' => 'laundry.edit', 'methods' => ['GET', 'POST'], 'file' => 'account_edit.php'],
    '/laundry/accounts/{id}/edit' => ['permission' => 'laundry.edit', 'methods' => ['GET', 'POST'], 'file' => 'account_edit.php'],
    '/laundry/types' => ['permission' => 'laundry.edit', 'methods' => ['GET', 'POST'], 'file' => 'types_list.php'],
    '/laundry/types/new' => ['permission' => 'laundry.edit', 'methods' => ['GET', 'POST'], 'file' => 'type_edit.php'],
    '/laundry/types/{id}/edit' => ['permission' => 'laundry.edit', 'methods' => ['GET', 'POST'], 'file' => 'type_edit.php'],
    '/laundry/settings' => ['permission' => 'laundry.edit', 'methods' => ['GET', 'POST'], 'file' => 'settings.php'],
];
foreach ($pages as $path => $def) {
    $file = (string)$def['file'];
    ModuleManager::registerRoute($path, [
        'module' => 'laundry',
        'methods' => $def['methods'],
        'permission' => $def['permission'],
        'handler' => static function () use ($file): void {
            require base_path('modules/laundry/pages/' . $file);
        },
    ]);
}

BulkActions::register('laundry_accounts', 'delete', [
    'label' => 'core.bulk.delete',
    'permission' => 'laundry.delete',
    'confirm' => 'core.bulk.confirm_delete',
    'run' => static function (array $ids, array $params): array {
        return ['done' => LaundryAccountsRepository::deleteByIds($ids), 'skipped' => []];
    },
]);

BulkActions::register('laundry_types', 'delete', [
    'label' => 'core.bulk.delete',
    'permission' => 'laundry.delete',
    'confirm' => 'core.bulk.confirm_delete',
    'run' => static function (array $ids, array $params): array {
        try {
            return ['done' => LaundryItemTypesRepository::deleteByIds($ids), 'skipped' => []];
        } catch (Throwable $e) {
            $skipped = [];
            foreach ($ids as $id) {
                $skipped[] = ['id' => (int)$id, 'reason' => $e->getMessage()];
            }
            return ['done' => 0, 'skipped' => $skipped];
        }
    },
]);

EventBus::on('core.news_item.section', static function ($payload) {
    if (!is_array($payload) || !empty($payload['is_folder'])) {
        return $payload;
    }
    $row = $payload['row'] ?? null;
    if (!is_array($row)) {
        return $payload;
    }
    if ((int)($row['ID'] ?? 0) !== LaundryConfig::ticketNewsId()) {
        return $payload;
    }
    if (!Auth::can('laundry.view')) {
        return $payload;
    }
    $accounts = LaundryAccountsRepository::names(true);
    $types = LaundryItemTypesRepository::listEnabled();
    $matrix = LaundryMovesRepository::balanceMatrix();
    $started = LaundryMovesRepository::hasAnyMoves();
    $open = Auth::can('laundry.ship') ? LaundryShipmentsRepository::listOpenWithLines() : [];
    $canShip = Auth::can('laundry.ship');
    $canEdit = Auth::can('laundry.edit');
    ob_start();
    require __DIR__ . '/views/_ticket_panel.php';
    $payload['html'] = ($payload['html'] ?? '') . (string)ob_get_clean();
    return $payload;
});

EventBus::on('core.news_item.handle_post', static function ($payload) {
    if (!is_array($payload) || !empty($payload['handled'])) {
        return $payload;
    }
    $action = (string)($payload['action'] ?? '');
    if ($action !== 'laundry_ship' && $action !== 'laundry_accept') {
        return $payload;
    }
    $id = (int)($payload['id'] ?? 0);
    if ($id !== LaundryConfig::ticketNewsId()) {
        return $payload;
    }
    if (!Auth::can('laundry.ship')) {
        return $payload;
    }
    $post = is_array($payload['post'] ?? null) ? $payload['post'] : [];
    $uid = (int)($payload['uid'] ?? Auth::userId());
    try {
        if ($action === 'laundry_ship') {
            $lines = [];
            $qtys = is_array($post['qty'] ?? null) ? $post['qty'] : [];
            foreach ($qtys as $typeId => $qty) {
                $lines[] = ['id_item_type' => (int)$typeId, 'qty' => (int)$qty];
            }
            LaundryShipmentsRepository::createShipment(
                LaundryConfig::termsAccountId(),
                LaundryConfig::laundryAccountId(),
                $lines,
                trim((string)($post['descr'] ?? '')),
                (float)str_replace(',', '.', (string)($post['weight'] ?? '0')),
                time(),
                $uid,
                $id
            );
        } else {
            $lines = [];
            $ret = is_array($post['ret'] ?? null) ? $post['ret'] : [];
            foreach ($ret as $lineId => $qty) {
                $lines[] = ['id_shipment_line' => (int)$lineId, 'qty' => (int)$qty];
            }
            LaundryShipmentsRepository::acceptReturn(
                $lines,
                trim((string)($post['descr'] ?? '')),
                (float)str_replace(',', '.', (string)($post['weight'] ?? '0')),
                time(),
                $uid,
                $id
            );
        }
        $payload['handled'] = true;
        $payload['redirect'] = '/news/' . $id . '#laundry';
    } catch (Throwable $e) {
        $payload['handled'] = true;
        $payload['error'] = $e->getMessage();
        // No redirect: news_item must render the flash error on the ticket page.
    }
    return $payload;
});
