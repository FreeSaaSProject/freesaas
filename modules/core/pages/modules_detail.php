<?php
declare(strict_types=1);

require_once base_path('modules/core/lib/ModulesAdmin.php');

$id = trim((string)Router::param('id'));
$card = $id !== '' ? ModuleCatalog::detail($id) : null;
if ($card === null) {
    http_response_code(404);
    echo t('core.modules.err.no_module');
    return;
}

$detailPath = '/modules/' . rawurlencode($id);
$handled = ModulesAdmin::handlePost($detailPath);
if ($handled !== null) {
    redirect($handled['redirect']);
}

$message = trim((string)($_GET['msg'] ?? ''));
$error = trim((string)($_GET['err'] ?? ''));
$displayName = ModuleCatalog::display($card['name'], $id);

View::render('modules_detail', [
    'title' => $displayName,
    'card' => $card,
    'detailPath' => $detailPath,
    'message' => $message,
    'error' => $error,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('core.menu.modules'), 'href' => '/modules'],
        ['label' => $displayName, 'current' => true],
    ]),
], 'layout', 'core');
