<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$provider = trim((string)($_POST['provider'] ?? $_GET['provider'] ?? 'alfa_ul'));
$kind = trim((string)($_POST['kind'] ?? ''));

try {
    if (!empty($_FILES['file']) && is_array($_FILES['file'])) {
        $saved = BanksCertStorage::saveUpload($provider, $kind, $_FILES['file']);
    } else {
        $b64 = (string)($_POST['content_base64'] ?? '');
        $filename = trim((string)($_POST['filename'] ?? ''));
        $saved = BanksCertStorage::saveBase64($provider, $kind, $b64, $filename);
    }
    if ($wantsJson) {
        AgentHttp::emit([
            'ok' => true,
            'provider' => BanksConfig::normalizeProvider($provider),
            'kind' => $kind,
            'path' => $saved['path'],
            'config_key' => $saved['config_key'],
            'bytes' => $saved['bytes'],
            'mtls' => BanksCertStorage::statusForProvider($provider),
        ]);
        return;
    }
    flash_set('ok', t('banks.settings.certs_saved', ['kind' => $kind]));
} catch (Throwable $e) {
    if ($wantsJson) {
        AgentHttp::error($e->getMessage(), 400);
        return;
    }
    flash_set('error', $e->getMessage());
}

redirect('/banks/settings?tab=alfa_ul');
