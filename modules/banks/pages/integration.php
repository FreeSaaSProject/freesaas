<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$id = (int)Router::param('id');
BanksIntegrationRepository::ensureAlfaUlSlots();
$int = BanksIntegrationRepository::find($id, true);
if (!$int) {
    if ($wantsJson) {
        AgentHttp::error(t('banks.err.not_found'), 404);
        return;
    }
    flash_set('error', t('banks.err.not_found'));
    redirect('/banks');
}

$message = '';
$error = '';
$canEdit = Auth::capability('SUPERADMIN_ACCESS');
$canConnect = Auth::can('banks.connect');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $canEdit) {
    $action = trim((string)($_POST['action'] ?? 'save'));
    try {
        if ($action === 'save') {
            BanksIntegrationRepository::update($id, [
                'label' => trim((string)($_POST['label'] ?? '')),
                'client_id' => trim((string)($_POST['client_id'] ?? '')),
                'client_secret' => (string)($_POST['client_secret'] ?? ''),
                'api_key' => (string)($_POST['api_key'] ?? ''),
                'scope' => trim((string)($_POST['scope'] ?? '')),
                'redirect_uri' => trim((string)($_POST['redirect_uri'] ?? '')),
                'mtls_pass' => (string)($_POST['mtls_pass'] ?? ''),
                'status' => trim((string)($_POST['status'] ?? 'draft')),
            ]);
            foreach (['cert', 'key', 'ca'] as $kind) {
                $field = 'mtls_' . $kind . '_file';
                if (!empty($_FILES[$field]) && is_array($_FILES[$field])
                    && (int)($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                    BanksCertStorage::saveUploadForIntegration($id, $kind, $_FILES[$field]);
                }
            }
            $message = t('core.common.saved');
        } elseif ($action === 'probe_scopes') {
            $conns = BankConnectionsRepository::listAll();
            $connId = 0;
            foreach ($conns as $c) {
                if ((int)($c['integration_id'] ?? 0) === $id && (string)($c['status'] ?? '') === 'active') {
                    $connId = (int)$c['id'];
                    break;
                }
            }
            if ($connId < 1) {
                throw new RuntimeException(t('banks.int.need_connect_first'));
            }
            $results = BankOAuth::runScopeProbeOnConnection($connId, $id);
            $okN = 0;
            foreach ($results as $r) {
                if (!empty($r['ok'])) {
                    $okN++;
                }
            }
            $message = t('banks.int.probe_done', ['ok' => $okN, 'total' => count($results)]);
        } elseif ($action === 'delete' && $canConnect) {
            // Only delete integration row; leave connections/certs files (user deletes connections separately).
            BanksIntegrationRepository::delete($id);
            if ($wantsJson) {
                AgentHttp::emit(['ok' => true, 'deleted' => $id]);
                return;
            }
            flash_set('ok', t('banks.int.deleted'));
            redirect('/banks');
        }
        if ($wantsJson) {
            AgentHttp::emit([
                'ok' => true,
                'message' => $message,
                'integration' => BanksIntegrationRepository::find($id),
                'mtls' => BanksCertStorage::statusForIntegration($id),
            ]);
            return;
        }
        flash_set('ok', $message !== '' ? $message : t('core.common.saved'));
        redirect('/banks/integrations/' . $id);
    } catch (Throwable $e) {
        if ($wantsJson) {
            AgentHttp::error($e->getMessage(), 400);
            return;
        }
        $error = $e->getMessage();
    }
}

$int = BanksIntegrationRepository::find($id, false);
$intFullHint = BanksIntegrationRepository::find($id, true);
$mtls = BanksCertStorage::statusForIntegration($id);
$meta = is_array($int['meta'] ?? null) ? $int['meta'] : [];
$probe = is_array($meta['scope_probe'] ?? null) ? $meta['scope_probe'] : null;
$redirectUri = BanksConfig::oauthRedirectUri('alfa_ul');
$related = [];
foreach (BankConnectionsRepository::listAll() as $c) {
    if ((int)($c['integration_id'] ?? 0) === $id) {
        $related[] = $c;
    }
}

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'integration' => $int,
        'mtls' => $mtls,
        'connections' => $related,
        'scope_probe' => $probe,
        'redirect_uri' => $redirectUri,
        'configured' => $intFullHint ? BanksIntegrationRepository::isConfigured($intFullHint) : false,
    ]);
    return;
}

View::render('integration', [
    'title' => t('banks.int.title', ['label' => (string)($int['label'] ?? '#' . $id)]),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('banks.menu.list'), 'href' => '/banks'],
        ['label' => (string)($int['label'] ?? '#' . $id), 'current' => true],
    ]),
    'message' => $message,
    'error' => $error,
    'int' => $int,
    'mtls' => $mtls,
    'probe' => $probe,
    'related' => $related,
    'redirectUri' => $redirectUri,
    'canEdit' => $canEdit,
    'canConnect' => $canConnect,
], 'layout', 'banks');
