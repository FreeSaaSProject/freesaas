<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$eventId = (int)(Router::param('id') ?? 0);

if (!Auth::capability('EVT_SERT') && !Auth::capability('SUPERADMIN_ACCESS')) {
    if ($wantsJson) {
        AgentHttp::error(t('certificates.event.no_capability'), 403);
        return;
    }
    flash_set('error', t('certificates.event.no_capability'));
    redirect('/calendar/events/' . $eventId . '/edit');
}

$code = trim((string)($_POST['cert_code'] ?? $_GET['code'] ?? ''));
$idCert = (int)($_POST['id_cert'] ?? $_GET['id_cert'] ?? 0);
$ref = $idCert > 0 ? $idCert : $code;

$result = CertificateProcessor::applyEvent($eventId, $ref);

if ($wantsJson) {
    if (empty($result['ok'])) {
        AgentHttp::error((string)($result['error'] ?? t('certificates.err.apply_failed')), 400, $result);
        return;
    }
    AgentHttp::emit(['ok' => true] + $result);
    return;
}

$ret = trim((string)($_POST['ret'] ?? ''));
if ($ret === '' || $ret[0] !== '/') {
    $ret = '/calendar/events/' . $eventId . '/edit';
}
if (empty($result['ok'])) {
    flash_set('error', (string)($result['error'] ?? t('certificates.err.apply_failed')));
} else {
    flash_set('ok', t('certificates.event.applied_ok', [
        'n' => number_format((float)$result['spend'], 0, '.', ' '),
    ]));
}
redirect($ret);
