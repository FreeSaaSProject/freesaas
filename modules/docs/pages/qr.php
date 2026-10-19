<?php
declare(strict_types=1);

/**
 * Public archive gate for printed documents (QR). No PII for guests:
 * definition name, number, print date and archive sign status only.
 */
$wantsJson = AgentHttp::wantsJson();
$hash = strtolower(trim((string)(Router::param('hash') ?? '')));
$row = DocsConfig::qrArchive() ? DocsInstancesRepository::findByHashExt($hash) : null;
if ($row === null || (int)$row['dt_print'] < 1) {
    if ($wantsJson) {
        AgentHttp::error(t('core.common.not_found'), 404);
        return;
    }
    http_response_code(404);
    View::render('error', ['title' => t('core.common.not_found'), 'message' => t('docs.qr.not_found')], 'layout_auth');
    return;
}

$canSign = Auth::check() && Auth::can('docs.sign');
$message = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!$canSign) {
        http_response_code(403);
        View::render('error', ['title' => t('docs.err.no_sign'), 'message' => t('docs.err.no_sign')], 'layout_auth');
        return;
    }
    DocsInstancesRepository::sign((int)$row['id']);
    $row = DocsInstancesRepository::findByHashExt($hash) ?: $row;
    $message = t('docs.qr.signed_ok');
}

$signerName = '';
if ((int)$row['id_admin_signed'] > 0 && class_exists('UsersRepository', false)) {
    $names = UsersRepository::staffNamesIncluding([(int)$row['id_admin_signed']]);
    $signerName = (string)($names[(int)$row['id_admin_signed']] ?? '');
}

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'id' => (int)$row['id'],
        'name' => (string)$row['def_name'],
        'dt_print' => (int)$row['dt_print'],
        'void' => $row['status'] === 'void',
        'signed' => (int)$row['dt_signed'] > 0,
        'dt_signed' => (int)$row['dt_signed'],
    ]);
    return;
}

View::render('qr', [
    'title' => t('docs.qr.title'),
    'row' => $row,
    'canSign' => $canSign,
    'canOpen' => Auth::check() && Auth::can('docs.view') && DocsConfig::canSeeKind((string)$row['kind']),
    'signerName' => $signerName,
    'message' => $message,
], 'layout_auth', 'docs');
