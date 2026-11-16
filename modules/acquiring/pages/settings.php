<?php
declare(strict_types=1);

$title = t('acquiring.settings.title');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (class_exists('Csrf', false) && !AgentHttp::wantsJson() && !Csrf::verify()) {
        Csrf::deny('csrf');
    }
    AcquiringConfig::saveFromPost($_POST);
    if (AgentHttp::wantsJson()) {
        AgentHttp::emit(['ok' => true]);
        return;
    }
    flash('ok', t('acquiring.settings.saved'));
    redirect('/acquiring/settings');
}

$providers = [];
foreach (AcquiringRegistry::all() as $p) {
    $providers[] = [
        'id' => $p->id(),
        'enabled' => $p->enabled(),
        'settings' => AcquiringConfig::providerSettings($p->id()),
    ];
}

if (AgentHttp::wantsJson()) {
    AgentHttp::emit([
        'ok' => true,
        'default_provider' => AcquiringConfig::defaultProvider(),
        'allow_client_choice' => AcquiringConfig::allowClientChoice(),
        'currency' => AcquiringConfig::currency(),
        'providers' => $providers,
    ]);
    return;
}

View::render('settings', [
    'title' => $title,
    'default_provider' => AcquiringConfig::defaultProvider(),
    'allow_client_choice' => AcquiringConfig::allowClientChoice(),
    'currency' => AcquiringConfig::currency(),
    'yk' => AcquiringConfig::providerSettings('yookassa'),
    'tb' => AcquiringConfig::providerSettings('tbank'),
    'breadcrumbs' => Breadcrumbs::withHome([['label' => $title, 'current' => true]]),
], 'layout', 'acquiring');
