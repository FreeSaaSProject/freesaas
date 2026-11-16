<?php
declare(strict_types=1);

$enabled = [];
foreach (AcquiringRegistry::enabled() as $p) {
    $enabled[] = $p->id();
}
$recent = AcquiringPaymentsRepository::list(['limit' => 10]);
$payload = [
    'ok' => true,
    'default_provider' => AcquiringConfig::defaultProvider(),
    'enabled_providers' => $enabled,
    'currency' => AcquiringConfig::currency(),
    'recent' => $recent['items'],
];

if (AgentHttp::wantsJson()) {
    AgentHttp::emit($payload);
    return;
}

View::render('status', [
    'title' => t('acquiring.status.title'),
    'payload' => $payload,
    'breadcrumbs' => Breadcrumbs::withHome([['label' => t('acquiring.status.title'), 'current' => true]]),
], 'layout', 'acquiring');
