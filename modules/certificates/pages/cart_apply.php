<?php
declare(strict_types=1);

$code = trim((string)($_POST['cert_code'] ?? ''));
CertificatesCart::setCode($code);

$clientId = 0;
if (class_exists('SitesGuest', false)) {
    $clientId = (int)SitesGuest::clientId();
}

if ($code === '') {
    flash_set('error', t('certificates.err.not_found_code'));
    CertificatesCart::clear();
} elseif ($clientId < 1) {
    flash_set('error', t('certificates.err.need_client'));
    CertificatesCart::clear();
} else {
    $v = CertificateProcessor::validate($code, $clientId, ['allow_unbound_holder' => true]);
    if (empty($v['ok'])) {
        flash_set('error', (string)($v['error'] ?? t('certificates.err.not_found_code')));
        CertificatesCart::clear();
    } else {
        flash_set('ok', t('certificates.cart.ok'));
    }
}

redirect('/cart');
