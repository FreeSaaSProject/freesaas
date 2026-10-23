<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$canManage = Auth::can('bi.manage');

if ($canManage) {
    $catalog = ReportRegistry::catalog();
    $mode = 'manage';
} else {
    $catalog = [];
    $seen = [];
    foreach (BiSharesRepository::listUsableForCurrentStaff() as $share) {
        $familyId = (string)($share['FAMILY'] ?? '');
        if ($familyId === '' || isset($seen[$familyId])) {
            continue;
        }
        $family = ReportRegistry::get($familyId);
        if ($family === null) {
            continue;
        }
        $seen[$familyId] = true;
        $noteKey = $family->readyNoteKey();
        $catalog[] = [
            'id' => $familyId,
            'title' => ReportRegistry::displayTitle((string)$family->titleKey()),
            'ready' => $family->isReady(),
            'ready_note' => $noteKey !== '' ? t($noteKey) : '',
            'custom' => false,
            'shipped' => ReportRegistry::isShippedId($familyId),
            'def_id' => 0,
            'shared' => true,
            'share_token' => (string)($share['TOKEN'] ?? ''),
            'share_label' => (string)($share['LABEL'] ?? ''),
            'share_expires' => (int)($share['DT_EXPIRE'] ?? 0),
        ];
    }
    $mode = 'shared';
}

if ($wantsJson) {
    foreach ($catalog as $i => $f) {
        if (!is_array($f)) {
            continue;
        }
        $fid = (string)($f['id'] ?? '');
        if ($fid === '') {
            continue;
        }
        $shareToken = trim((string)($f['share_token'] ?? ''));
        $open = '/bi/run?family=' . rawurlencode($fid);
        if ($shareToken !== '') {
            $open .= '&share=' . rawurlencode($shareToken);
        }
        $catalog[$i]['open_url'] = $open;
    }
    $payload = [
        'ok' => true,
        'mode' => $mode,
        'families' => $catalog,
        'link_note' => 'Use each family open_url for staff links. /bi/shares is the director share-admin page, not a report.',
    ];
    if ($canManage) {
        $payload['hint'] = [
            'legend' => 'GET /bi/legend?format=json',
            'get' => 'GET /bi/families/{id}?format=json',
            'run' => 'POST /bi/work?format=json action=run&family=&year=&month=',
            'validate' => 'POST /bi/families/new?format=json action=validate',
            'save' => 'POST /bi/families/new?format=json action=save',
            'docs' => 'docs/agents/bi.md',
        ];
    } else {
        $payload['hint'] = [
            'run' => 'POST /bi/work?format=json action=run&family=&share=&year=&month=',
            'docs' => 'docs/agents/bi.md',
        ];
    }
    AgentHttp::emit($payload);
    return;
}

$flash = '';
if (isset($_GET['flash'])) {
    if ($_GET['flash'] === 'family_deleted') {
        $flash = t('bi.family.deleted');
    }
}

View::render('index', [
    'title' => t('bi.menu.list'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('bi.menu.group'), 'href' => '/bi'],
        ['label' => t('bi.menu.list'), 'current' => true],
    ]),
    'families' => $catalog,
    'canManage' => $canManage,
    'flash' => $flash,
], 'layout', 'bi');
