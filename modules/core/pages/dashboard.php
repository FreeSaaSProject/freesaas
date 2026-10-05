<?php
declare(strict_types=1);

require_once base_path('modules/core/lib/DashboardWidgets.php');
require_once base_path('modules/core/lib/UserPrefsRepository.php');
require_once base_path('modules/core/lib/MenuOrder.php');
require_once base_path('modules/core/lib/Breadcrumbs.php');

$uid = Auth::userId();
$message = '';
$error = '';

$catalog = DashboardWidgets::catalog();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        if (!empty($_POST['reset_defaults'])) {
            DashboardWidgets::resetDefaults($uid);
            MenuOrder::resetDefaults($uid);
            UserPrefsRepository::delete($uid, 'rail.peek');
            $message = t('core.dashboard.reset_done');
        } else {
            $order = $_POST['order'] ?? [];
            if (!is_array($order)) {
                $order = [];
            }
            $enabledRaw = $_POST['enabled'] ?? [];
            $enabledIds = [];
            if (is_array($enabledRaw)) {
                foreach ($enabledRaw as $id => $on) {
                    if ($on) {
                        $enabledIds[] = (string)$id;
                    }
                }
            }

            $settingsRaw = $_POST['settings'] ?? [];
            $settingsById = [];
            if (is_array($settingsRaw)) {
                foreach ($settingsRaw as $id => $vals) {
                    if (is_array($vals)) {
                        $settingsById[(string)$id] = $vals;
                    }
                }
            }

            DashboardWidgets::saveLayout(
                $uid,
                array_map('strval', $order),
                $enabledIds,
                $catalog,
                $settingsById
            );

            $menuOrder = $_POST['menu_order'] ?? [];
            if (!is_array($menuOrder)) {
                $menuOrder = [];
            }
            MenuOrder::save($uid, array_map('strval', $menuOrder));
            UserPrefsRepository::set($uid, 'rail.peek', !empty($_POST['rail_peek']) ? '1' : '0');

            $message = t('core.common.saved');
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$layout = DashboardWidgets::resolveLayout($uid, $catalog);
$menuLayout = MenuOrder::layoutForSettings();
$railPeek = UserPrefsRepository::get($uid, 'rail.peek') === '1';

View::render('dashboard', [
    'title' => t('core.dashboard.title'),
    'layout' => $layout,
    'menuLayout' => $menuLayout,
    'railPeek' => $railPeek,
    'message' => $message,
    'error' => $error,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('core.dashboard.title'), 'current' => true],
    ]),
]);
