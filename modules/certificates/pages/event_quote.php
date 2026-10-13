<?php
declare(strict_types=1);

$eventId = (int)(Router::param('id') ?? 0);
$code = trim((string)($_GET['code'] ?? ''));
$idCert = (int)($_GET['id_cert'] ?? 0);
$ref = $idCert > 0 ? $idCert : $code;

$quote = CertificateProcessor::quoteEvent($eventId, $ref);
if (empty($quote['ok'])) {
    AgentHttp::error((string)($quote['error'] ?? t('certificates.err.apply_failed')), 400, $quote);
    return;
}
AgentHttp::emit(['ok' => true, 'quote' => $quote]);
