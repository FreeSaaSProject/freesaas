<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$id = (int)(Router::param('id') ?? 0);
$row = DocsInstancesRepository::find($id);
if ($row === null || !DocsConfig::canSeeKind((string)$row['kind'])) {
    $msg = $row === null ? t('docs.err.not_found', ['id' => $id]) : t('docs.err.no_medical');
    if ($wantsJson) {
        AgentHttp::error($msg, $row === null ? 404 : 403);
        return;
    }
    http_response_code($row === null ? 404 : 403);
    View::render('error', ['title' => $msg, 'message' => $msg]);
    return;
}
$def = DocsDefsRepository::find((int)$row['id_def']);
$version = DocsDefsRepository::version((int)$row['id_version']);
$printed = (int)$row['dt_print'] > 0;
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !$printed) {
    try {
        if ($row['status'] === 'void') {
            throw new RuntimeException(t('docs.err.locked'));
        }
        if ($def !== null && !empty($def['require_medcard']) && DocsSpawner::eventMedcardBlocking((int)$row['id_event'])) {
            throw new RuntimeException(t('docs.err.medcard_not_filled'));
        }
        DocsInstancesRepository::markPrinted($id, DocsRender::render($row), $def !== null ? (int)$def['expire_days'] : 0);
        if ($wantsJson) {
            AgentHttp::emit(['ok' => true, 'id' => $id, 'printed' => true, 'item' => DocsInstancesRepository::find($id)]);
            return;
        }
        redirect('/documents/' . $id . '/print?autoprint=1');
        return;
    } catch (Throwable $e) {
        if ($wantsJson) {
            AgentHttp::error($e->getMessage(), 400);
            return;
        }
        $error = $e->getMessage();
    }
}

$html = $printed ? (string)$row['print_snapshot'] : DocsRender::render($row);

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'id' => $id,
        'printed' => $printed,
        'dt_print' => (int)$row['dt_print'],
        'html' => $html,
    ]);
    return;
}

View::render('print', [
    'title' => (string)$row['def_name'] . ' #' . $id,
    'row' => $row,
    'html' => $html,
    'printed' => $printed,
    'error' => $error,
    'orientation' => (string)($version['orientation'] ?? 'portrait'),
    'format' => (string)($version['format'] ?? 'A4'),
    'autoPrint' => $printed && isset($_GET['autoprint']),
], '', 'docs');
