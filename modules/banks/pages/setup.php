<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$provider = BanksConfig::normalizeProvider((string)(Router::param('provider') ?? ''));
if ($provider === '' || BanksSetupWizard::steps($provider) === []) {
    if ($wantsJson) {
        AgentHttp::error(t('banks.err.provider'), 404);
        return;
    }
    flash_set('error', t('banks.err.provider'));
    redirect('/banks');
}

$message = '';
$error = '';
$stepParam = trim((string)($_GET['step'] ?? $_POST['step'] ?? ''));

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = trim((string)($_POST['wizard_action'] ?? ''));
    $stepId = trim((string)($_POST['step_id'] ?? $stepParam));
    try {
        if ($action === 'skip' && $stepId !== '') {
            BanksSetupWizard::markSkipped($provider, $stepId);
            $message = t('banks.setup.skipped');
        } elseif ($action === 'done' && $stepId !== '') {
            if ($stepId === 'egress' && !BanksEgressIp::isUsable()) {
                throw new RuntimeException(t('banks.setup.blocker_local_ip', [
                    'ip' => (string)(BanksEgressIp::detect()['ip'] ?: '—'),
                ]));
            }
            BanksSetupWizard::markDone($provider, $stepId);
            $message = t('banks.setup.marked_done');
        } elseif ($action === 'save_common' || $action === 'save_step') {
            $post = $_POST;
            // Map wizard fields into BanksConfig keys for this provider (+ common when posted).
            $tab = $provider;
            if (!empty($_FILES)) {
                foreach (['cert', 'key', 'ca'] as $kind) {
                    $field = 'alfa_ul_mtls_' . $kind . '_file';
                    if (!empty($_FILES[$field]) && is_array($_FILES[$field])
                        && (int)($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                        BanksCertStorage::saveUpload('alfa_ul', $kind, $_FILES[$field]);
                    }
                }
            }
            if ($action === 'save_common') {
                BanksConfig::saveFromPost($post, false, 'common');
            } else {
                BanksConfig::saveFromPost($post, false, $tab);
            }
            if ($stepId !== '') {
                BanksSetupWizard::markDone($provider, $stepId);
            }
            $message = t('core.common.saved');
        } elseif ($action === 'csr_generate') {
            BanksAlfaCsr::generate([
                'organization' => (string)($_POST['organization'] ?? ''),
                'inn' => (string)($_POST['inn'] ?? ''),
                'common_name' => (string)($_POST['common_name'] ?? ''),
                'email' => (string)($_POST['email'] ?? ''),
                'force' => !empty($_POST['force']),
            ]);
            BanksSetupWizard::markDone($provider, 'prod_csr');
            $message = t('banks.csr.generated', [
                'file' => 'CSR',
                'subject' => (string)(BanksAlfaCsr::csrStatus()['subject'] ?? ''),
            ]);
        }
        if ($wantsJson) {
            AgentHttp::emit([
                'ok' => true,
                'message' => $message,
                'setup' => BanksSetupWizard::snapshot($provider),
            ]);
            return;
        }
        $snap = BanksSetupWizard::snapshot($provider);
        $next = (string)$snap['current_step'];
        redirect('/banks/setup/' . rawurlencode($provider) . '?step=' . rawurlencode($next !== '' ? $next : $stepId) . '&saved=1');
    } catch (Throwable $e) {
        if ($wantsJson) {
            AgentHttp::error($e->getMessage(), 400);
            return;
        }
        $error = $e->getMessage();
    }
}

$snap = BanksSetupWizard::snapshot($provider);
if ($stepParam !== '') {
    $activeStep = $stepParam;
} else {
    $activeStep = (string)$snap['current_step'];
}
if (isset($_GET['saved']) && $error === '' && $message === '') {
    $message = t('core.common.saved');
}

$cfg = BanksConfig::formValues();
$mtlsAlfa = BanksCertStorage::statusForProvider('alfa_ul');
$csrAlfa = BanksAlfaCsr::csrStatus();
$egress = $snap['egress'];
$moveTypes = AccountMoveTypesRepository::names(true);

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'provider' => $provider,
        'setup' => $snap,
        'active_step' => $activeStep,
        'settings' => $cfg,
        'mtls' => ['alfa_ul' => $mtlsAlfa],
        'csr' => ['alfa_ul' => $csrAlfa],
        'egress_ip' => $egress,
        'redirect_uri' => BanksConfig::oauthRedirectUri($provider),
    ]);
    return;
}

View::render('setup', [
    'title' => t('banks.setup.title', ['bank' => BanksConfig::providerLabel($provider)]),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('banks.menu.list'), 'href' => '/banks'],
        ['label' => BanksConfig::providerLabel($provider), 'current' => true],
    ]),
    'message' => $message,
    'error' => $error,
    'provider' => $provider,
    'providerLabel' => BanksConfig::providerLabel($provider),
    'snap' => $snap,
    'activeStep' => $activeStep,
    'cfg' => $cfg,
    'mtlsAlfa' => $mtlsAlfa,
    'csrAlfa' => $csrAlfa,
    'egress' => $egress,
    'moveTypes' => $moveTypes,
    'canConnect' => Auth::can('banks.connect'),
], 'layout', 'banks');
