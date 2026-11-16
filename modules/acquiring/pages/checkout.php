<?php
declare(strict_types=1);

// Stub confirm: GET with stub=1 marks payment succeeded for local demos.
if ((string)($_GET['stub'] ?? '') === '1') {
    $ref = (string)($_GET['ref'] ?? '');
    $provider = (string)($_GET['provider'] ?? '');
    $row = AcquiringPaymentsRepository::findByProviderRef($provider, $ref);
    if ($row !== null) {
        Acquiring::markSucceeded((int)$row['id'], ['mode' => 'stub_confirm']);
    }
    if (AgentHttp::wantsJson()) {
        AgentHttp::emit(['ok' => true, 'stub' => true, 'payment' => $row]);
        return;
    }
    redirect('/cabinet');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    if (AgentHttp::wantsJson()) {
        AgentHttp::error('POST required', 405);
        return;
    }
    http_response_code(405);
    echo 'POST required';
    return;
}

if (class_exists('Csrf', false) && Csrf::providedToken() !== '') {
    if (!Csrf::verify()) {
        Csrf::deny();
    }
}
$orderId = (int)($_POST['order_id'] ?? $_GET['order_id'] ?? 0);
$provider = (string)($_POST['provider'] ?? '');
$returnUrl = (string)($_POST['return_url'] ?? '/cabinet');
$result = Acquiring::createForOrder($orderId, $provider !== '' ? $provider : null, $returnUrl);

if (AgentHttp::wantsJson()) {
    if (empty($result['ok'])) {
        AgentHttp::error((string)($result['error'] ?? t('acquiring.err.create')), 400);
        return;
    }
    AgentHttp::emit($result + ['message' => t('acquiring.checkout.ok')]);
    return;
}

if (empty($result['ok'])) {
    flash('err', (string)($result['error'] ?? t('acquiring.err.create')));
    redirect($returnUrl);
}
$payUrl = (string)($result['pay_url'] ?? $returnUrl);
redirect($payUrl);
