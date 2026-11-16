<?php
declare(strict_types=1);

$title = t('client_portal.settings.title');
$registered = ClientPortalSections::collect();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (class_exists('Csrf', false) && !AgentHttp::wantsJson() && !Csrf::verify()) {
        Csrf::deny('csrf');
    }
    $vis = [];
    $weights = [];
    $postShow = $_POST['show'] ?? [];
    $postWeight = $_POST['weight'] ?? [];
    if (!is_array($postShow)) {
        $postShow = [];
    }
    if (!is_array($postWeight)) {
        $postWeight = [];
    }
    foreach ($registered as $row) {
        $id = $row['id'];
        $vis[$id] = !empty($postShow[$id]);
        $weights[$id] = (int)($postWeight[$id] ?? $row['weight']);
    }
    // Keep previously known keys even if module temporarily off
    foreach (ClientPortalConfig::visibility() as $id => $show) {
        if (!array_key_exists($id, $vis) && isset($postShow[$id])) {
            $vis[$id] = !empty($postShow[$id]);
        }
    }
    ClientPortalConfig::save($vis, $weights);
    if (AgentHttp::wantsJson()) {
        AgentHttp::emit(['ok' => true, 'visibility' => ClientPortalConfig::visibility(), 'weights' => ClientPortalConfig::weights()]);
        return;
    }
    flash('ok', t('client_portal.settings.saved'));
    redirect('/client-portal/settings');
}

$visibility = ClientPortalConfig::visibility();
$weights = ClientPortalConfig::weights();
$rows = [];
foreach ($registered as $row) {
    $id = $row['id'];
    $rows[] = [
        'id' => $id,
        'module' => $row['module'],
        'title' => $row['title'],
        'show' => array_key_exists($id, $visibility) ? $visibility[$id] : true,
        'weight' => $weights[$id] ?? $row['weight'],
    ];
}

if (AgentHttp::wantsJson()) {
    AgentHttp::emit([
        'ok' => true,
        'sections' => $rows,
        'visibility' => $visibility,
        'weights' => $weights,
    ]);
    return;
}

View::render('settings', [
    'title' => $title,
    'rows' => $rows,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => $title, 'current' => true],
    ]),
], 'layout', 'client_portal');
