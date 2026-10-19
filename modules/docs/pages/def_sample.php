<?php
declare(strict_types=1);

/**
 * Sample print with demo data: a stored definition (/documents/defs/{id}/sample[?version=id])
 * or a library file before import (/documents/defs/import/sample?pack=&file=). Nothing is stored.
 */
$wantsJson = AgentHttp::wantsJson();
$id = (int)(Router::param('id') ?? 0);
$def = null;
$ver = null;
$backUrl = '/documents/defs';

if ($id > 0) {
    $def = DocsDefsRepository::find($id);
    if ($def !== null) {
        $vid = (int)($_GET['version'] ?? 0);
        $ver = $vid > 0 ? DocsDefsRepository::version($vid) : DocsDefsRepository::currentVersion($def);
        if ($ver !== null && (int)$ver['id_def'] !== (int)$def['id']) {
            $ver = null;
        }
        $backUrl = '/documents/defs/' . $id . '/edit';
    }
} else {
    if (!Auth::can('docs.manage')) {
        http_response_code(403);
        View::render('error', ['title' => t('core.common.forbidden'), 'message' => t('core.common.forbidden')]);
        return;
    }
    $packId = (string)($_GET['pack'] ?? '');
    $data = DocsLibrary::readPackFile($packId, (string)($_GET['file'] ?? ''));
    if ($data !== null) {
        $def = [
            'code' => (string)$data['code'],
            'name' => (string)$data['name'],
            'kind' => (string)$data['kind'],
            'expire_days' => (int)($data['expire_days'] ?? 0),
        ];
        $ver = [
            'schema' => $data['schema'],
            'print_html' => (string)$data['print_html'],
            'orientation' => (string)($data['orientation'] ?? 'portrait'),
            'format' => (string)($data['paper'] ?? 'A4'),
            'copies' => (int)($data['copies'] ?? 1),
        ];
        $backUrl = '/documents/defs/import?pack=' . rawurlencode($packId);
    }
}

if ($def === null || $ver === null) {
    if ($wantsJson) {
        AgentHttp::error(t('core.common.not_found'), 404);
        return;
    }
    http_response_code(404);
    View::render('error', ['title' => t('core.common.not_found'), 'message' => t('core.common.not_found')]);
    return;
}

$html = DocsRender::renderSample($def, $ver);
if ($wantsJson) {
    AgentHttp::emit(['ok' => true, 'code' => (string)$def['code'], 'html' => $html]);
    return;
}

View::render('print', [
    'title' => t('docs.sample.title', ['name' => (string)$def['name']]),
    'row' => null,
    'html' => $html,
    'printed' => false,
    'sample' => true,
    'backUrl' => $backUrl,
    'error' => '',
    'orientation' => (string)$ver['orientation'],
    'format' => (string)$ver['format'],
    'autoPrint' => false,
], '', 'docs');
