<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
DocsDefsRepository::ensureSeeded();
$id = (int)(Router::param('id') ?? 0);
$isNew = $id < 1;

$def = null;
$ver = null;
if (!$isNew) {
    $def = DocsDefsRepository::find($id);
    if ($def === null) {
        if ($wantsJson) {
            AgentHttp::error(t('docs.def.err.not_found', ['id' => $id]), 404);
            return;
        }
        http_response_code(404);
        View::render('error', ['title' => t('core.common.not_found'), 'message' => t('docs.def.err.not_found', ['id' => $id])]);
        return;
    }
    $ver = DocsDefsRepository::currentVersion($def);
}

$form = [
    'code' => (string)($def['code'] ?? ''),
    'name' => (string)($def['name'] ?? ''),
    'kind' => (string)($def['kind'] ?? 'consent'),
    'scope' => (string)($def['scope'] ?? 'event'),
    'trigger_mode' => (string)($def['trigger_mode'] ?? 'manual'),
    'trigger_types' => implode(',', (array)($def['trigger_types'] ?? [])),
    'trigger_procs' => implode(',', (array)($def['trigger_procs'] ?? [])),
    'expire_days' => (int)($def['expire_days'] ?? 0),
    'require_medcard' => (int)($def['require_medcard'] ?? 0),
    'enabled' => $isNew ? 1 : (int)($def['enabled'] ?? 0),
    'num' => (int)($def['num'] ?? 0),
    'schema_json' => $ver !== null
        ? (string)json_encode($ver['schema'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        : "{\n  \"sections\": []\n}",
    'print_html' => (string)($ver['print_html'] ?? '<h2>{doc.name}</h2>' . "\n" . '{fields}' . "\n" . '{qr}'),
    'orientation' => (string)($ver['orientation'] ?? 'portrait'),
    'format' => (string)($ver['format'] ?? 'A4'),
    'copies' => (int)($ver['copies'] ?? 1),
];

$message = '';
$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $post = $_POST;
    // Agents send only what they change; the HTML form always posts every field (checkbox absent = off).
    if ($wantsJson) {
        $post = array_merge($form, $post);
    }
    $form = array_merge($form, array_intersect_key($post, $form));
    $form['enabled'] = !empty($post['enabled']) ? 1 : 0;
    $form['require_medcard'] = !empty($post['require_medcard']) ? 1 : 0;
    if (is_array($post['schema_json'] ?? null)) {
        $post['schema_json'] = (string)json_encode($post['schema_json'], JSON_UNESCAPED_UNICODE);
        $form['schema_json'] = $post['schema_json'];
    }
    try {
        $errors = DocsSchema::validate((string)($post['schema_json'] ?? ''));
        if ($errors !== []) {
            throw new InvalidArgumentException(implode('; ', $errors));
        }
        $meta = DocsDefsRepository::metaFromPost($post, $id);
        $verData = [
            'schema_json' => (string)($post['schema_json'] ?? ''),
            'print_html' => (string)($post['print_html'] ?? ''),
            'orientation' => (string)($post['orientation'] ?? 'portrait'),
            'format' => (string)($post['format'] ?? 'A4'),
            'copies' => (int)($post['copies'] ?? 1),
        ];
        if ($isNew) {
            $newId = DocsDefsRepository::create($meta, $verData);
            if ($wantsJson) {
                AgentHttp::emit(['ok' => true, 'id' => $newId, 'def' => DocsDefsRepository::find($newId)]);
                return;
            }
            redirect('/documents/defs/' . $newId . '/edit?saved=1');
            return;
        }
        DocsDefsRepository::updateMeta($id, $meta);
        $vid = DocsDefsRepository::saveVersionIfChanged($id, $verData);
        $def = DocsDefsRepository::find($id);
        $ver = DocsDefsRepository::version($vid);
        if ($wantsJson) {
            AgentHttp::emit(['ok' => true, 'id' => $id, 'def' => $def, 'version' => (int)($ver['version'] ?? 0)]);
            return;
        }
        $message = t('docs.def.saved', ['n' => (int)($ver['version'] ?? 0)]);
        $form['schema_json'] = (string)json_encode($ver['schema'] ?? ['sections' => []], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    } catch (Throwable $e) {
        if ($wantsJson) {
            AgentHttp::error($e->getMessage(), 400);
            return;
        }
        $error = $e->getMessage();
    }
}

if (isset($_GET['saved']) && $message === '') {
    $message = t('core.common.saved');
}

if ($wantsJson) {
    AgentHttp::emit(['ok' => true, 'is_new' => $isNew, 'def' => $def, 'form' => $form]);
    return;
}

$schemaForKeys = DocsSchema::parse($form['schema_json']);
View::render('def_edit', [
    'title' => $isNew ? t('docs.defs.new') : t('docs.def.title', ['name' => (string)$def['name']]),
    'isNew' => $isNew,
    'def' => $def,
    'form' => $form,
    'versions' => $isNew ? [] : DocsDefsRepository::versions($id),
    'placeholders' => DocsRender::placeholderKeys($schemaForKeys, (string)$form['kind']),
    'message' => $message,
    'error' => $error,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('docs.menu.defs'), 'href' => '/documents/defs'],
        ['label' => $isNew ? t('docs.defs.new') : (string)$def['name'], 'current' => true],
    ]),
], 'layout', 'docs');
