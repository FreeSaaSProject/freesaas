<?php
declare(strict_types=1);

/** Download one definition (current version) as an importable template file. */
$id = (int)(Router::param('id') ?? 0);
$def = DocsDefsRepository::find($id);
if ($def === null) {
    http_response_code(404);
    View::render('error', ['title' => t('core.common.not_found'), 'message' => t('docs.def.err.not_found', ['id' => $id])]);
    return;
}
$data = DocsLibrary::export($def);
if (AgentHttp::wantsJson()) {
    AgentHttp::emit(['ok' => true, 'template' => $data]);
    return;
}
header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $def['code'] . '.json"');
header('Cache-Control: no-store');
echo DocsLibrary::encode($data);
