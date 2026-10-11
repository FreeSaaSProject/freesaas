<?php
declare(strict_types=1);

$hash = strtolower(trim((string)($_GET['q'] ?? $_POST['q'] ?? '')));
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$wantsJson = AgentHttp::wantsJson();

if (!QrScanGate::canScan()) {
    QrScanGate::denyGuest();
    return;
}

$userId = Auth::userId();
$req = array_merge($_GET, $_POST);
if ($method === 'POST') {
    foreach ($_POST as $k => $v) {
        $req[$k] = $v;
    }
}

$mutateKeys = [
    'weight', 'weight2', 'amount', 'confirm', 'doDismiss', 'doCancel', 'cancel',
    'idRash', 'id_rash', 'idStockNew', 'id_stock_new', 'doForceRevi', 'reallyDoForceRevi',
    'undoAct', 'action', 'do',
];
$mutatingGet = false;
if ($method === 'GET') {
    foreach ($mutateKeys as $k) {
        if (!isset($req[$k]) || $req[$k] === '' || $req[$k] === null) {
            continue;
        }
        if ($k === 'do' && (int)$req[$k] === 0) {
            continue;
        }
        $mutatingGet = true;
        break;
    }
}

if ($method === 'POST') {
    $result = QrScanService::apply($userId, $hash, $req);
} else {
    $result = QrScanService::inspect($userId, $hash, $req);
    if ($mutatingGet) {
        $result['messages'][] = [
            'level' => 'warn',
            'text' => t('stickers.qr.err.get_mutate'),
        ];
        $result['confirm_post'] = true;
    }
}

if ($wantsJson) {
    if (!empty($result['error']) && empty($result['ok']) && ($result['phase'] ?? '') === 'error') {
        AgentHttp::error((string)$result['error'], 400);
        return;
    }
    AgentHttp::emit([
        'ok' => !empty($result['ok']) || empty($result['error']),
        'phase' => $result['phase'] ?? '',
        'hash' => $result['hash'] ?? $hash,
        'token' => $result['token'] ?? '',
        'done' => !empty($result['done']),
        'sticker_id' => (int)($result['sticker_id'] ?? 0),
        'product_name' => (string)($result['product_name'] ?? ''),
        'stock_name' => (string)($result['stock_name'] ?? ''),
        'remaining' => (float)($result['remaining'] ?? 0),
        'weight_req' => !empty($result['weight_req']),
        'messages' => $result['messages'] ?? [],
        'forms' => $result['forms'] ?? [],
        'actions' => $result['actions'] ?? [],
        'meta' => $result['meta'] ?? [],
        'error' => (string)($result['error'] ?? ''),
        'can_revise' => !empty($result['can_revise']),
    ]);
    return;
}

View::render('qr2', [
    'title' => t('stickers.qr.title'),
    'result' => $result,
    'hash' => $hash,
    'confirm_post' => !empty($mutatingGet),
], 'layout_auth', 'stickers');
