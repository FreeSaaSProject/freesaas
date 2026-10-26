<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$message = '';
$error = '';

$resolveTab = static function () : string {
    return BanksConfig::resolveSettingsTab(
        (string)($_POST['banks_settings_tab'] ?? $_GET['tab'] ?? 'common')
    );
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $post = $_POST;
        $tab = $resolveTab();
        // Session/JSON agent: optional base64 cert fields on same save (alfa_ul tab / full agent).
        if ($tab === 'alfa_ul' || $wantsJson) {
            foreach (['cert', 'key', 'ca'] as $kind) {
                $b64Key = 'alfa_ul_mtls_' . $kind . '_base64';
                if (trim((string)($post[$b64Key] ?? '')) === '') {
                    continue;
                }
                BanksCertStorage::saveBase64(
                    'alfa_ul',
                    $kind,
                    (string)$post[$b64Key],
                    (string)($post['alfa_ul_mtls_' . $kind . '_filename'] ?? ($kind . '.pem'))
                );
                unset($post[$b64Key], $post['alfa_ul_mtls_' . $kind . '_filename']);
            }
            foreach (['cert', 'key', 'ca'] as $kind) {
                $field = 'alfa_ul_mtls_' . $kind . '_file';
                if (!empty($_FILES[$field]) && is_array($_FILES[$field])
                    && (int)($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                    BanksCertStorage::saveUpload('alfa_ul', $kind, $_FILES[$field]);
                }
            }
        }
        if ($wantsJson) {
            // Agent / full JSON body: partial keys only (existing behaviour).
            BanksConfig::saveFromPost($post, true);
        } else {
            BanksConfig::saveFromPost($post, false, $tab);
        }
        if ($wantsJson) {
            AgentHttp::emit([
                'ok' => true,
                'settings' => BanksConfig::formValues(),
                'mtls' => ['alfa_ul' => BanksCertStorage::statusForProvider('alfa_ul')],
                'egress_ip' => BanksEgressIp::detect(),
                'tab' => $tab,
            ]);
            return;
        }
        redirect('/banks/settings?' . http_build_query(['tab' => $tab, 'saved' => '1']));
    } catch (Throwable $e) {
        if ($wantsJson) {
            AgentHttp::error($e->getMessage(), 400);
            return;
        }
        $error = $e->getMessage();
    }
}

$activeTab = $resolveTab();
if (isset($_GET['saved']) && $error === '') {
    $message = t('core.common.saved');
}

$cfg = BanksConfig::formValues();
$moveTypes = AccountMoveTypesRepository::names(true);
$connectedProviders = [];
foreach (BankConnectionsRepository::listAll() as $row) {
    if ((string)($row['status'] ?? '') !== 'active') {
        continue;
    }
    $prov = (string)($row['provider'] ?? '');
    if ($prov !== '') {
        $connectedProviders[$prov] = true;
    }
}
$providers = [];
foreach (BanksConfig::PROVIDERS as $p) {
    $providers[] = [
        'id' => $p,
        'label' => BanksConfig::providerLabel($p),
        'configured' => BanksConfig::providerConfigured($p),
        'connected' => !empty($connectedProviders[$p]),
    ];
}
$mtlsAlfa = BanksCertStorage::statusForProvider('alfa_ul');
$csrAlfa = BanksAlfaCsr::csrStatus();
$egressIp = BanksEgressIp::detect();
$flash = flash_take();
if ($flash && $message === '' && $error === '') {
    if (($flash['type'] ?? '') === 'ok') {
        $message = (string)$flash['message'];
    } else {
        $error = (string)$flash['message'];
    }
}

$tabs = [
    ['id' => 'common', 'title' => t('banks.settings.tab_common')],
];
foreach ($providers as $p) {
    $tabs[] = [
        'id' => (string)$p['id'],
        'title' => (string)$p['label'],
        'configured' => !empty($p['configured']),
        'connected' => !empty($p['connected']),
    ];
}

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'settings' => $cfg,
        'providers' => $providers,
        'tabs' => array_map(static fn (array $t): string => (string)$t['id'], $tabs),
        'tab' => $activeTab,
        'mtls' => ['alfa_ul' => $mtlsAlfa],
        'csr' => ['alfa_ul' => $csrAlfa],
        'egress_ip' => $egressIp,
        'redirect_uri_alfa_ul' => BanksConfig::oauthRedirectUri('alfa_ul'),
    ]);
    return;
}

View::render('settings', [
    'title' => t('banks.settings.title'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('banks.menu.list'), 'href' => '/banks'],
        ['label' => t('banks.menu.settings'), 'current' => true],
    ]),
    'message' => $message,
    'error' => $error,
    'cfg' => $cfg,
    'moveTypes' => $moveTypes,
    'providers' => $providers,
    'tabs' => $tabs,
    'activeTab' => $activeTab,
    'mtlsAlfa' => $mtlsAlfa,
    'csrAlfa' => $csrAlfa,
    'egressIp' => $egressIp,
], 'layout', 'banks');
