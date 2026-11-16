<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
DocsDefsRepository::ensureSeeded();

if (!Auth::can('docs.edit') || !Auth::can('docs.medical')) {
    if ($wantsJson) {
        AgentHttp::error(t('docs.err.no_medical'), 403);
        return;
    }
    http_response_code(403);
    View::render('error', ['title' => t('docs.err.no_medical'), 'message' => t('docs.err.no_medical')]);
    return;
}

if (!DocsFillTemplates::tableReady()) {
    if ($wantsJson) {
        AgentHttp::error(t('docs.fill.err.not_ready'), 503);
        return;
    }
    http_response_code(503);
    View::render('error', ['title' => t('docs.fill.err.not_ready'), 'message' => t('docs.fill.err.not_ready')]);
    return;
}

$uid = (int)(Auth::userId() ?? 0);
$defs = DocsFillTemplates::visitDefs();
$defId = (int)($_POST['def'] ?? $_GET['def'] ?? 0);
if ($defId < 1 && $defs !== []) {
    $defId = (int)$defs[0]['id'];
}
// Agent may request clinic template with admin=0 + docs.manage.
$adminParam = $_POST['admin'] ?? $_GET['admin'] ?? null;
$adminId = $uid;
if ($adminParam !== null && (string)$adminParam === '0') {
    if (!DocsFillTemplates::canEdit(0)) {
        if ($wantsJson) {
            AgentHttp::error(t('docs.fill.err.clinic_forbidden'), 403);
            return;
        }
        http_response_code(403);
        View::render('error', ['title' => t('docs.fill.err.clinic_forbidden'), 'message' => t('docs.fill.err.clinic_forbidden')]);
        return;
    }
    $adminId = 0;
} elseif ($adminParam !== null && (int)$adminParam > 0) {
    $adminId = (int)$adminParam;
    if (!DocsFillTemplates::canEdit($adminId)) {
        if ($wantsJson) {
            AgentHttp::error(t('docs.err.no_medical'), 403);
            return;
        }
        http_response_code(403);
        View::render('error', ['title' => t('docs.err.no_medical'), 'message' => t('docs.err.no_medical')]);
        return;
    }
}

$def = $defId > 0 ? DocsDefsRepository::find($defId) : null;
if ($def !== null && !DocsFillTemplates::isVisitKind((string)$def['kind'])) {
    $def = null;
    $defId = 0;
}
$ver = $def !== null ? DocsDefsRepository::currentVersion($def) : null;
$schema = $ver !== null ? $ver['schema'] : ['sections' => []];
$tpl = $defId > 0 ? DocsFillTemplates::find($defId, $adminId) : null;
$fillFields = $tpl !== null ? $tpl['fields'] : [];
$enabled = $tpl === null || $tpl['enabled'];

$message = '';
$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        if ($def === null || $ver === null) {
            throw new InvalidArgumentException(t('docs.fill.err.pick_def'));
        }
        if (!DocsFillTemplates::canEdit($adminId)) {
            throw new RuntimeException(t('docs.err.no_medical'));
        }
        $raw = is_array($_POST['fill'] ?? null) ? $_POST['fill'] : [];
        // HTML checkbox: absent = off. JSON agents omit keys they do not change → keep enabled.
        if ($wantsJson && !array_key_exists('enabled', $_POST)) {
            $enabled = $tpl === null || $tpl['enabled'];
        } else {
            $enabled = !empty($_POST['enabled']);
        }
        // JSON patch: merge with existing fields (send "" to clear a key); HTML form posts the full set.
        if ($wantsJson && $tpl !== null && $tpl['fields'] !== []) {
            $raw = array_merge($tpl['fields'], $raw);
        }
        $tpl = DocsFillTemplates::save($defId, $adminId, $raw, $enabled);
        $fillFields = $tpl['fields'];
        $message = t('docs.fill.msg.saved');
        if ($wantsJson) {
            AgentHttp::emit(['ok' => true, 'template' => $tpl, 'def' => $def]);
            return;
        }
    } catch (Throwable $e) {
        if ($wantsJson) {
            AgentHttp::error($e->getMessage(), 400);
            return;
        }
        $error = $e->getMessage();
    }
}

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'admin_id' => $adminId,
        'scope' => $adminId === 0 ? 'clinic' : 'doctor',
        'defs' => $defs,
        'def' => $def,
        'schema' => $schema,
        'fillable' => DocsFillTemplates::fillableCatalog($schema),
        'template' => $tpl,
        'fields' => $fillFields,
        'enabled' => $enabled,
        'hint' => 'To draft defaults: docs.defs.list (kind=medcard_visit) → docs.defs.sample (sample_fields+fillable) → docs.fill_templates.save with fill{field_id:phrase}. Clinic: admin=0 + docs.manage.',
    ]);
    return;
}

View::render('fill_templates', [
    'title' => t('docs.fill.title'),
    'defs' => $defs,
    'defId' => $defId,
    'def' => $def,
    'schema' => $schema,
    'fillFields' => $fillFields,
    'enabled' => $enabled,
    'adminId' => $adminId,
    'isClinic' => $adminId === 0,
    'message' => $message,
    'error' => $error,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('docs.menu.list'), 'href' => '/documents'],
        ['label' => t('docs.menu.fill_templates'), 'current' => true],
    ]),
], 'layout', 'docs');
