<?php
declare(strict_types=1);

$mode = trim((string)(Router::param('mode') ?? $_GET['mode'] ?? ''));
if ($mode === '' && isset($_POST['mode'])) {
    $mode = trim((string)$_POST['mode']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_client'])) {
    OnecConsole::clearClient();
    flash('ok', t('terminals.onec.flash.client_cleared'));
    redirect('/terminals/1c');
}

$state = OnecConsole::run(
    $mode,
    $_GET,
    $_POST,
    (string)($_SERVER['REQUEST_METHOD'] ?? 'GET')
);

if (AgentHttp::wantsJson()) {
    $payload = $state;
    // Keep agent payload free of huge duplicated dumps when empty.
    AgentHttp::emit(['ok' => true, 'terminals_onec' => $payload]);
}

$title = t('terminals.onec.title');
if ($mode !== '') {
    $crumbs = Breadcrumbs::withHome([
        ['label' => t('terminals.menu.list'), 'href' => '/terminals'],
        ['label' => t('terminals.menu.onec'), 'href' => '/terminals/1c'],
        ['label' => $mode, 'current' => true],
    ]);
} else {
    $crumbs = Breadcrumbs::withHome([
        ['label' => t('terminals.menu.list'), 'href' => '/terminals'],
        ['label' => t('terminals.menu.onec'), 'current' => true],
    ]);
}

View::render('onec', [
    'title' => $title,
    'state' => $state,
    'breadcrumbs' => $crumbs,
], 'layout', 'terminals');
