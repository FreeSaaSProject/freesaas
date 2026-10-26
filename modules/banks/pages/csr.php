<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$action = trim((string)($_POST['action'] ?? $_GET['action'] ?? ''));

if ($method === 'GET' && ($action === 'download' || isset($_GET['download']))) {
    $st = BanksAlfaCsr::csrStatus();
    if (empty($st['readable'])) {
        if ($wantsJson) {
            AgentHttp::error(t('banks.err.csr_missing'), 404);
            return;
        }
        flash_set('error', t('banks.err.csr_missing'));
        redirect('/banks/settings?tab=alfa_ul');
    }
    $name = BanksAlfaCsr::downloadFilename(
        (string)(Config::get('app', 'name', '') ?: 'Company')
    );
    // Prefer org from CSR subject O= if present
    if (preg_match('/O=([^,]+)/', (string)($st['subject'] ?? ''), $m)) {
        $name = BanksAlfaCsr::downloadFilename(trim($m[1]));
    }
    header('Content-Type: application/pkcs10');
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $name) . '"');
    header('Content-Length: ' . (string)$st['bytes']);
    header('X-Content-Type-Options: nosniff');
    readfile((string)$st['path']);
    exit;
}

if ($method !== 'POST') {
    if ($wantsJson) {
        AgentHttp::emit(['ok' => true, 'csr' => BanksAlfaCsr::csrStatus()]);
        return;
    }
    redirect('/banks/settings?tab=alfa_ul');
}

try {
    $in = $wantsJson ? AgentOps::requestInput() : $_POST;
    $result = BanksAlfaCsr::generate([
        'organization' => (string)($in['organization'] ?? $in['org'] ?? ''),
        'inn' => (string)($in['inn'] ?? ''),
        'common_name' => (string)($in['common_name'] ?? $in['cn'] ?? ''),
        'email' => (string)($in['email'] ?? ''),
        'client_id' => (string)($in['client_id'] ?? Config::get('banks', 'alfa_ul_client_id', '')),
        'force' => !empty($in['force']) || (string)($in['force'] ?? '') === '1',
    ]);
    if ($wantsJson) {
        AgentHttp::emit([
            'ok' => true,
            'csr' => BanksAlfaCsr::csrStatus(),
            'generated' => $result,
            'download_path' => '/banks/settings/csr?action=download',
        ]);
        return;
    }
    flash_set('ok', t('banks.csr.generated', [
        'file' => (string)$result['csr_filename'],
        'subject' => (string)$result['subject'],
    ]));
} catch (Throwable $e) {
    if ($wantsJson) {
        AgentHttp::error($e->getMessage(), 400);
        return;
    }
    flash_set('error', $e->getMessage());
}

redirect('/banks/settings?tab=alfa_ul');
