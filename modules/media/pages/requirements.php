<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$scopeTable = trim((string)($_GET['scope_table'] ?? $_POST['scope_table'] ?? ''));
$scopeId = (int)($_GET['scope_id'] ?? $_POST['scope_id'] ?? 0);

$canManageScope = static function (string $table, int $id): bool {
    if ($id < 1 || !in_array($table, MediaConfig::scopeTables(), true)) {
        return false;
    }
    if (Auth::capability('SUPERADMIN_ACCESS')) {
        return true;
    }
    if ($table === 'base_procedure_types' && Auth::can('procedures.types')) {
        return true;
    }
    if ($table === 'base_products' && Auth::can('catalog.edit')) {
        return true;
    }
    return false;
};

$scopeEditHref = static function (string $table, int $id): string {
    if ($id < 1) {
        return '';
    }
    if ($table === 'base_procedure_types') {
        return '/procedure-types/' . $id . '/edit';
    }
    if ($table === 'base_products') {
        return '/products/' . $id . '/edit';
    }
    return '';
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!$canManageScope($scopeTable, $scopeId)) {
        if ($wantsJson) {
            AgentHttp::error(t('media.err.forbidden'), 403);
            return;
        }
        http_response_code(403);
        echo t('media.err.forbidden');
        return;
    }
    $action = (string)($_POST['action'] ?? 'save');
    try {
        if ($action === 'delete') {
            if (!Auth::capability('SUPERADMIN_ACCESS') && !$canManageScope($scopeTable, $scopeId)) {
                throw new RuntimeException(t('media.err.forbidden'));
            }
            MediaRequirementsRepository::delete((int)($_POST['id'] ?? 0));
            if ($wantsJson) {
                AgentHttp::emit(['ok' => true]);
                return;
            }
            $ret = trim((string)($_POST['ret'] ?? ''));
            if ($ret !== '' && strpos($ret, '/') === 0) {
                redirect($ret);
                return;
            }
            redirect('/media/requirements?scope_table=' . rawurlencode($scopeTable) . '&scope_id=' . $scopeId . '&saved=1');
            return;
        }
        $req = $_POST['req'] ?? [];
        if (!is_array($req)) {
            $req = [];
        }
        $saved = [];
        foreach (MediaConfig::kinds() as $kind) {
            $block = is_array($req[$kind] ?? null) ? $req[$kind] : [];
            $on = !empty($block['on']);
            $min = max(1, min(20, (int)($block['min'] ?? 1)));
            $label = trim((string)($block['label'] ?? ''));
            if ($on) {
                $saved[] = MediaRequirementsRepository::upsert([
                    'scope_table' => $scopeTable,
                    'scope_id' => $scopeId,
                    'kind' => $kind,
                    'min_count' => $min,
                    'enabled' => 1,
                    'label' => $label,
                ]);
            } else {
                MediaRequirementsRepository::deleteByScopeKind($scopeTable, $scopeId, $kind);
            }
        }
        if ($wantsJson) {
            AgentHttp::emit([
                'ok' => true,
                'ids' => $saved,
                'items' => MediaRequirementsRepository::listForScope($scopeTable, $scopeId, false),
            ]);
            return;
        }
        $ret = trim((string)($_POST['ret'] ?? ''));
        if ($ret !== '' && strpos($ret, '/') === 0) {
            $sep = strpos($ret, '?') === false ? '?' : '&';
            redirect($ret . $sep . 'media_req=1');
            return;
        }
        redirect('/media/requirements?scope_table=' . rawurlencode($scopeTable) . '&scope_id=' . $scopeId . '&saved=1');
        return;
    } catch (Throwable $e) {
        if ($wantsJson) {
            AgentHttp::error($e->getMessage(), 400);
            return;
        }
        http_response_code(400);
        echo $e->getMessage();
        return;
    }
}

// Journal (all scopes) — director only when no specific scope.
if ($scopeId < 1) {
    if (!Auth::capability('SUPERADMIN_ACCESS')) {
        if ($wantsJson) {
            AgentHttp::error(t('media.err.forbidden'), 403);
            return;
        }
        http_response_code(403);
        echo t('media.err.forbidden');
        return;
    }
    $filters = [
        'scope_table' => trim((string)($_GET['scope_table'] ?? '')),
        'scope_id' => (int)($_GET['scope_id'] ?? 0),
        'kind' => trim((string)($_GET['kind'] ?? '')),
        'enabled' => (string)($_GET['enabled'] ?? ''),
        'q' => trim((string)($_GET['q'] ?? '')),
    ];
    $items = MediaRequirementsRepository::listAll($filters);
    if ($wantsJson) {
        AgentHttp::emit(['ok' => true, 'items' => $items, 'filters' => $filters]);
        return;
    }
    View::render('requirements_list', [
        'title' => t('media.req.journal_title'),
        'items' => $items,
        'filters' => $filters,
        'scopeEditHref' => $scopeEditHref,
        'message' => isset($_GET['saved']) ? t('media.flash.saved') : '',
        'breadcrumbs' => Breadcrumbs::withHome([
            ['label' => t('media.menu.list'), 'href' => '/media'],
            ['label' => t('media.menu.requirements'), 'current' => true],
        ]),
    ], 'layout', 'media');
    return;
}

if (!Auth::capability('SUPERADMIN_ACCESS') && !$canManageScope($scopeTable, $scopeId)) {
    if ($wantsJson) {
        AgentHttp::error(t('media.err.forbidden'), 403);
        return;
    }
    http_response_code(403);
    echo t('media.err.forbidden');
    return;
}

$items = MediaRequirementsRepository::listForScope($scopeTable, $scopeId, false);

if ($wantsJson) {
    AgentHttp::emit(['ok' => true, 'items' => $items, 'scope_table' => $scopeTable, 'scope_id' => $scopeId]);
    return;
}

View::render('requirements', [
    'title' => t('media.req.title'),
    'items' => $items,
    'scope_table' => $scopeTable,
    'scope_id' => $scopeId,
    'ret' => '',
    'message' => isset($_GET['saved']) ? t('media.flash.saved') : '',
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('media.menu.list'), 'href' => '/media'],
        ['label' => t('media.menu.requirements'), 'href' => '/media/requirements'],
        ['label' => t('media.req.title'), 'current' => true],
    ]),
], 'layout', 'media');
