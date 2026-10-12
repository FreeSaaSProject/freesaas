<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$flash = '';
$error = '';
$created = null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && function_exists('request_raw_body')) {
    $rawBody = request_raw_body();
    if ($rawBody !== '') {
        $decodedBody = json_decode($rawBody, true);
        if (is_array($decodedBody)) {
            foreach ($decodedBody as $k => $v) {
                if (!array_key_exists((string)$k, $_POST)) {
                    $_POST[(string)$k] = $v;
                }
            }
        }
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = trim((string)($_POST['action'] ?? 'create'));
    if ($action === 'revoke') {
        $id = (int)($_POST['id'] ?? 0);
        BiSharesRepository::revoke($id);
        if ($wantsJson) {
            AgentHttp::emit(['ok' => true, 'action' => 'revoke', 'id' => $id]);
            return;
        }
        redirect('/bi/shares?flash=revoked');
    }

    try {
        $paramsRaw = [];
        if (!empty($_POST['params_json']) && is_string($_POST['params_json'])) {
            $decoded = json_decode($_POST['params_json'], true);
            if (is_array($decoded)) {
                $paramsRaw = $decoded;
            }
        }
        foreach (['year', 'month', 'dayshow', 'variant', 'id_operator', 'id_admin', 'id_good'] as $k) {
            if (isset($_POST[$k]) && (string)$_POST[$k] !== '') {
                $paramsRaw[$k] = $_POST[$k];
            }
        }
        $staffRaw = $_POST['staff_user_ids'] ?? [];
        $created = BiSharesRepository::create([
            'family' => (string)($_POST['family'] ?? ''),
            'audience' => (string)($_POST['audience'] ?? 'guest'),
            'ttl' => (string)($_POST['ttl'] ?? '1d'),
            'label' => (string)($_POST['label'] ?? ''),
            'staff_user_ids' => $staffRaw,
            'params' => $paramsRaw,
            'accept_partial_filters' => !empty($_POST['accept_partial_filters']),
        ]);
        if ($wantsJson) {
            AgentHttp::emit(['ok' => true, 'action' => 'create', 'share' => $created]);
            return;
        }
        $flash = t('bi.shares.created');
        if (empty($created['filter_coverage']['ok'])) {
            $flash .= ' ' . t('bi.shares.warn.partial_filters');
        }
    } catch (InvalidArgumentException $e) {
        $code = $e->getMessage();
        if ($code === 'ttl_1y_staff_only') {
            $error = t('bi.shares.err.ttl_1y');
        } elseif ($code === 'staff_allowlist_empty') {
            $error = t('bi.shares.err.staff_empty');
        } elseif ($code === 'filters_partial') {
            $error = t('bi.shares.err.filters_partial');
        } elseif ($code === 'invalid_family') {
            $error = t('bi.shares.err.family');
        } else {
            $error = t('bi.shares.err.family');
        }
        if ($wantsJson) {
            $extra = ['detail' => $code];
            if ($code === 'filters_partial') {
                $extra['filter_coverage'] = BiSharesRepository::filterCoverage(
                    (string)($_POST['family'] ?? ''),
                    $paramsRaw
                );
            }
            AgentHttp::error($error, 400, $extra);
            return;
        }
    } catch (Throwable $e) {
        $error = t('bi.shares.err.create');
        if ($wantsJson) {
            AgentHttp::error($error, 500, ['detail' => $e->getMessage()]);
            return;
        }
    }
}

$shares = BiSharesRepository::list(200);
$families = ReportRegistry::catalog();

$staff = BiFilterOptions::shareStaffPicker();

if ($wantsJson && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    AgentHttp::emit([
        'ok' => true,
        'shares' => $shares,
        'families' => $families,
        'ttl_kinds' => ReportParams::TTL_KINDS,
        'ttl_kinds_guest' => ReportParams::TTL_KINDS_GUEST,
        'audiences' => ReportParams::AUDIENCES,
        'staff_picker' => array_map(static function (array $u): array {
            return [
                'id' => (int)$u['id'],
                'name' => (string)($u['name'] !== '' ? $u['name'] : $u['login']),
            ];
        }, $staff),
        'notes' => [
            '1y_ttl' => 'staff audience only',
            'superadmin' => 'opens any staff share even if not in allowlist',
            'assist_team' => 'omitted from staff_picker',
            'staff_allowlist' => 'missing/[]/blank = any staff; non-blank invalid ([0],[-1],bad) rejected before normalize',
            'filters' => 'locked id_* require full dataset coverage unless accept_partial_filters',
            'rate_limit' => 'atomic beginRun/endRun, 8/min + one in-flight per share',
        ],
    ]);
    return;
}

if (isset($_GET['flash']) && $_GET['flash'] === 'revoked') {
    $flash = t('bi.shares.revoked');
}

View::render('shares', [
    'title' => t('bi.shares.title'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('bi.menu.group'), 'href' => '/bi'],
        ['label' => t('bi.menu.shares'), 'current' => true],
    ]),
    'shares' => $shares,
    'families' => $families,
    'staff' => $staff,
    'created' => $created,
    'flash' => $flash,
    'error' => $error,
], 'layout', 'bi');
